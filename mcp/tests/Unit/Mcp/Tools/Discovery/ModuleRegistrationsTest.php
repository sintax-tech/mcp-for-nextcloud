<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Mcp\Tools\Discovery;

use OCA\Mcp\AppInfo\Application;
use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCA\Mcp\Mcp\Tools\Discovery\ModuleRegistrations;
use OCA\Mcp\Tests\Unit\Mcp\Tools\Catalog\FeatureProbe;
use OCA\Mcp\Tests\Unit\Mcp\Tools\Catalog\ProbeModule;
use OCA\Mcp\Tests\Unit\Mcp\Tools\Catalog\ProbeRegistration;
use OCA\Mcp\Tests\Unit\Mcp\Tools\Catalog\ReadProbeTool;
use OCA\Mcp\Tests\Unit\Mcp\Tools\Catalog\RegisteredProbeModule;
use OCA\Mcp\Tests\Unit\Mcp\Tools\Catalog\WrongRegistrationModule;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/** Application::MODULES drives the registrations: a module's own bindings, then the capability registry. */
final class ModuleRegistrationsTest extends TestCase {
	/** Catalogs without a registration contribute no bindings or probes. */
	public function testOnlyRegistrationsOfCatalogModulesRun(): void {
		self::assertSame([\OCA\Mcp\Modules\Files\FilesRegistration::class, \OCA\Mcp\Modules\Notes\NotesRegistration::class, \OCA\Mcp\Modules\Calendar\CalendarRegistration::class, \OCA\Mcp\Modules\Contacts\ContactsRegistration::class, \OCA\Mcp\Modules\Tasks\TasksRegistration::class, \OCA\Mcp\Modules\Deck\DeckRegistration::class,
			\OCA\Mcp\Modules\Talk\TalkRegistration::class, \OCA\Mcp\Modules\People\PeopleRegistration::class, \OCA\Mcp\Modules\Mail\MailRegistration::class,
			\OCA\Mcp\Modules\Tables\TablesRegistration::class, \OCA\Mcp\Modules\Forms\FormsRegistration::class, \OCA\Mcp\Modules\Polls\PollsRegistration::class, \OCA\Mcp\Modules\Collectives\CollectivesRegistration::class], array_map(static fn (object $registration): string => $registration::class, ModuleRegistrations::of(Application::MODULES)));
		self::assertSame([], ModuleRegistrations::of([ProbeModule::class]));
		$registrations = ModuleRegistrations::of([ProbeModule::class, RegisteredProbeModule::class]);
		self::assertCount(1, $registrations);
		self::assertInstanceOf(ProbeRegistration::class, $registrations[0]);
	}

	/** The module binds its own services first; the registry then reads the module's probes from the container. */
	public function testTheRegistryGetsTheProbesOfTheModules(): void {
		$services = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(static function (string $name, \Closure $factory) use (&$services): void {
			$services[$name] = $factory;
		});
		ModuleRegistrations::register($context, [ProbeModule::class, RegisteredProbeModule::class]);
		self::assertSame([ReadProbeTool::class, CapabilityRegistry::class], array_keys($services));

		$asked = [];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use (&$asked): object {
			$asked[] = $id;
			return $id === LoggerInterface::class ? $this->createMock(LoggerInterface::class) : new $id();
		});
		$registry = $services[CapabilityRegistry::class]($container);
		self::assertSame([LoggerInterface::class], $asked);
		self::assertTrue($registry->supports('probe.feature'));
		self::assertSame([LoggerInterface::class, FeatureProbe::class], $asked);
	}

	/**
	 * The real resolution, through the factories ModuleRegistrations hands the registry: a probe whose resolution
	 * throws (container, constructor, autoload) answers false with one warning, and a healthy one still answers.
	 */
	public function testAProbeThatFailsToResolveIsIsolated(): void {
		$resolve = new \ReflectionMethod(ModuleRegistrations::class, 'resolve');
		$asked = [];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static function (string $id) use (&$asked): object {
			$asked[] = $id;
			return $id === FeatureProbe::class ? new FeatureProbe(true, ['probe.other']) : throw new \RuntimeException('autoload');
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with('MCP capability probe failed: {probe}: {exception_class} (code {exception_code})',
			['app' => 'mcp', 'probe' => ProbeRegistration::class, 'exception_class' => \RuntimeException::class, 'exception_code' => 0]);
		$registry = new CapabilityRegistry($resolve->invoke(null, $container, [ProbeRegistration::class, FeatureProbe::class]), $logger);
		self::assertSame([], $asked);
		foreach ([1, 2] as $ignored) {
			self::assertFalse($registry->supports('probe.feature'));
			self::assertTrue($registry->supports('probe.other'));
		}
		self::assertSame([ProbeRegistration::class, FeatureProbe::class], $asked);
	}

	/** Non-catalog modules and registrations of the wrong type are both refused. */
	public function testARegistrationOfTheWrongTypeFails(): void {
		$legacy = new class implements \OCA\Mcp\Mcp\Tools\ToolModule {
			/** @return list<array<string, mixed>> No fixture tools. */
			public function definitions(): array { return []; }
			/** @param string $name Tool name. @param array<string, mixed> $arguments Arguments. @param string $userId Actor. @return array<string, mixed> No fixture result. */
			public function call(string $name, array $arguments, string $userId): array { return []; }
		};
		foreach ([$legacy::class, WrongRegistrationModule::class] as $module) {
			try { ModuleRegistrations::of([$module]); self::fail('Invalid module registration accepted'); }
			catch (\LogicException $error) {
				self::assertStringContainsString($module, $error->getMessage());
				self::assertStringContainsString($module === $legacy::class ? 'does not extend' : 'does not implement', $error->getMessage());
			}
		}
	}
}
