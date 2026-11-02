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
	/** Legacy modules and catalogs without a registration contribute nothing. */
	public function testOnlyRegistrationsOfCatalogModulesRun(): void {
		self::assertSame([\OCA\Mcp\Modules\Notes\NotesRegistration::class], array_map(static fn (object $registration): string => $registration::class, ModuleRegistrations::of(Application::MODULES)));
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
		$logger->expects(self::once())->method('warning')->with('MCP capability probe failed',
			['app' => 'mcp', 'probe' => ProbeRegistration::class, 'exception_class' => \RuntimeException::class]);
		$registry = new CapabilityRegistry($resolve->invoke(null, $container, [ProbeRegistration::class, FeatureProbe::class]), $logger);
		self::assertSame([], $asked);
		foreach ([1, 2] as $ignored) {
			self::assertFalse($registry->supports('probe.feature'));
			self::assertTrue($registry->supports('probe.other'));
		}
		self::assertSame([ProbeRegistration::class, FeatureProbe::class], $asked);
	}

	/** A module naming a class that is not a ModuleRegistration is refused. */
	public function testARegistrationOfTheWrongTypeFails(): void {
		$this->expectException(\LogicException::class);
		ModuleRegistrations::of([WrongRegistrationModule::class]);
	}
}
