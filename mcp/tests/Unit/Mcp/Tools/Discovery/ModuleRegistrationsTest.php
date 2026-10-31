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
		self::assertSame([], ModuleRegistrations::of(Application::MODULES));
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

	/** A module naming a class that is not a ModuleRegistration is refused. */
	public function testARegistrationOfTheWrongTypeFails(): void {
		$this->expectException(\LogicException::class);
		ModuleRegistrations::of([WrongRegistrationModule::class]);
	}
}
