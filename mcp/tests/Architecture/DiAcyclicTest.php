<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Architecture;

use OCA\Mcp\AppInfo\Application;
use OCA\Mcp\Platform\FileAccess\WriteVisibility;
use OCA\Mcp\Platform\FileAccess\LockAwareWrite;
use OCA\Mcp\Access\ResourceScope\NativeCalendars;
use OCA\Mcp\Modules\Calendar\Selftest\CalendarSelftestReader;
use OCA\Mcp\Tests\Architecture\Construction\{ConstructionGraph, Registrations, ServiceResolver};
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use LogicException;

/** Real internal construction, all factories and module roots, plus controls for every DI failure mode. */
final class DiAcyclicTest extends TestCase {
	private function resolver(?array $factories = null): ServiceResolver {
		return new ServiceResolver($factories ?? Registrations::capture($this), function (string $class): object {
			self::assertFalse(str_starts_with($class, 'OCA\\Mcp\\'), 'Internal services must never be doubled');
			self::assertContains($class, \OCA\Mcp\Tests\Architecture\Inventory\AppTree::json(__DIR__ . '/external-services.json'), 'Unreviewed external DI type');
			return $this->createMock($class);
		});
	}

	public function testEveryRegisteredFactoryAndEveryModuleRootIsConstructed(): void {
		$resolver = $this->resolver();
		$tools = [];
		foreach (Application::MODULES as $module) {
			self::assertTrue(is_subclass_of($module, \OCA\Mcp\Mcp\Tools\CatalogModule::class), $module);
			$tools = [...$tools, ...$module::catalog()];
		}
		// A catalog resolves its tools lazily: building them here proves every work class is autowired or registered.
		$resolver->resolveAll([...Application::MODULES, ...$tools]);
		self::assertNotEmpty($tools);
		self::assertCount(16, Application::MODULES);
		$captured = array_keys($resolver->factories); $invoked = array_keys($resolver->invoked); sort($captured); sort($invoked);
		self::assertSame($captured, $invoked);
		self::assertNotEmpty($resolver->edges);
		ConstructionGraph::assertAcyclic(ConstructionGraph::constructors(array_keys($resolver->edges)));
		if ($proof = getenv('MCP_ARCH_DI_PROOF')) { file_put_contents($proof, json_encode(['external' => array_keys($resolver->externalTypes), 'factories' => $captured, 'constructed' => array_keys($resolver->edges)], JSON_PRETTY_PRINT)); }
	}

	public function testVisibilityCannotBecomeNullableOrDefaulted(): void {
		$parameters = (new ReflectionClass(LockAwareWrite::class))->getConstructor()->getParameters();
		$visibility = array_values(array_filter($parameters, static fn ($p) => $p->getName() === 'visibility'));
		self::assertCount(1, $visibility);
		self::assertSame(WriteVisibility::class, $visibility[0]->getType()->getName());
		self::assertFalse($visibility[0]->allowsNull());
		self::assertFalse($visibility[0]->isDefaultValueAvailable());
	}

	public function testWrongBindingWithUnchangedKeyFails(): void {
		$factories = Registrations::capture($this);
		$factories[CalendarSelftestReader::class] = static fn () => new \stdClass();
		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('Wrong service type');
		$this->resolver($factories)->resolveAll(Application::MODULES);
	}

	public function testNewInvalidRegistrationCannotFallOutsideTheCoverageSet(): void {
		$factories = Registrations::capture($this);
		$factories[\OCA\Mcp\Platform\Numbers\DecimalCounter::class] = [self::class, 'wrongFactory'];
		$this->expectException(LogicException::class);
		$this->resolver($factories)->resolveAll(Application::MODULES);
	}

	public static function wrongFactory(): object { return new \stdClass(); }

	public function testMissingPortsFailWithoutGenericInternalDoubles(): void {
		foreach ([\OCA\Mcp\Mcp\Resources\FileTextResource::class, WriteVisibility::class, NativeCalendars::class, \OCA\Mcp\Access\Guests\NativeGuests::class, \OCA\Mcp\Mcp\Resources\NoteResource::class, \OCA\Mcp\Platform\Dav\CalDavObjects::class, \OCA\Mcp\Platform\Dav\CalDavWrite::class, \OCA\Mcp\Access\ResourceScope\ScopedCalendarLookup::class, \OCA\Mcp\Access\References\ReadableDeckCard::class, \OCA\Mcp\Access\References\ReadableCalendarEvent::class] as $port) {
			$factories = Registrations::capture($this); unset($factories[$port]);
			try { $this->resolver($factories)->get($port); self::fail('Missing port resolved'); }
			catch (LogicException $error) { self::assertStringContainsString('Missing internal binding', $error->getMessage()); }
		}
	}

	public function testFactoryOnlyCycleAndInterfaceBindingCycleFail(): void {
		foreach ([
			['A' => static fn ($c) => $c->get('B'), 'B' => static fn ($c) => $c->get('A')],
			['I' => static fn ($c) => $c->get('Implementation'), 'Implementation' => static fn ($c) => $c->get('I')],
		] as $factories) {
			try { $this->resolver($factories)->get(array_key_first($factories)); self::fail('Cycle accepted'); }
			catch (LogicException $error) { self::assertStringContainsString('Construction cycle:', $error->getMessage()); }
		}
	}

	public function testReflectionConstructorCycleAndInterfaceBindingCycleFail(): void {
		try { ConstructionGraph::assertAcyclic(ConstructionGraph::constructors([ConstructorCycleA::class, ConstructorCycleB::class])); self::fail('Reflected constructor cycle accepted'); }
		catch (LogicException $error) { self::assertStringContainsString(ConstructorCycleA::class, $error->getMessage()); }
		$factories = [ConstructorPort::class => static fn ($c) => $c->get(ConstructorPortImplementation::class)];
		$this->expectException(LogicException::class);
		$this->resolver($factories)->get(ConstructorPort::class);
	}

	public function testGraphControlsAndDeferredClosureDoNotInventCycles(): void {
		ConstructionGraph::assertAcyclic(['A' => ['B'], 'B' => ['C']]);
		$lazy = new ServiceResolver([\stdClass::class => static fn ($c) => (object)['later' => static fn () => $c->get(\stdClass::class)]], static fn () => new \stdClass());
		self::assertInstanceOf(\stdClass::class, $lazy->get(\stdClass::class));
		$this->expectException(LogicException::class);
		ConstructionGraph::assertAcyclic(['A' => ['B'], 'B' => ['A']]);
	}
}

final class ConstructorCycleA { public function __construct(public ConstructorCycleB $b) {} }
final class ConstructorCycleB { public function __construct(public ConstructorCycleA $a) {} }
interface ConstructorPort {}
final class ConstructorPortImplementation implements ConstructorPort { public function __construct(public ConstructorPort $port) {} }
