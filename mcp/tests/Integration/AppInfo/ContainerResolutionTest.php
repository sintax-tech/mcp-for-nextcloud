<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\AppInfo;

use OCA\Mcp\AppInfo\Application;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Runs every actual registration factory against the native container, without executing tool operations. */
final class ContainerResolutionTest extends TestCase {
	/** @return void Every captured binding and each legacy/new module root resolves to its declared type. */
	public function testAllRegisteredFactoriesAndModuleRootsResolveNatively(): void {
		$factories = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(static function (string $key, callable $factory) use (&$factories): void {
			self::assertArrayNotHasKey($key, $factories, 'Duplicate binding');
			$factories[$key] = $factory;
		});
		(new ReflectionClass(Application::class))->newInstanceWithoutConstructor()->register($context);
		self::assertNotEmpty($factories);
		$container = (new Application())->getContainer();
		$invoked = [];
		foreach ($factories as $key => $factory) {
			$value = $factory($container);
			self::assertInstanceOf($key, $value, 'Wrong native factory binding: ' . $key);
			self::initialize($value);
			self::initialize(Server::get($key));
			$invoked[] = $key;
		}
		self::assertSame(array_keys($factories), $invoked);
		self::assertCount(14, Application::MODULES);
		self::assertContains(\OCA\Mcp\Modules\Collectives\CollectivesModule::class, Application::MODULES);
		foreach (Application::MODULES as $root) { $value = Server::get($root); self::assertInstanceOf($root, $value); self::initialize($value); }
	}

	/** @return void Native fresh-container negative cases remain mandatory, not part of the unit environment failures. */
	public function testMissingBoundaryBindingsFailInTheRealContainer(): void {
		(new BoundaryBindingsTest('testMissingBoundaryRegistrationsFailOnFirstResolution'))->testMissingBoundaryRegistrationsFailOnFirstResolution();
	}

	/** @param object $service Native service or lazy ghost. @return void Force constructor without business operations. */
	private static function initialize(object $service): void {
		$reflection = new ReflectionClass($service);
		if (method_exists($reflection, 'initializeLazyObject')) { $reflection->initializeLazyObject($service); }
	}

	/** The explicit Talk factory has the same native dependencies as the previous automatic construction. @return void */
	public function testTalkServicesFactoryMatchesTheNativeAutowire(): void {
		$server = $this->createMock(\OC\ServerContainer::class);
		$server->method('get')->with(\OCP\App\IAppManager::class)->willReturn(Server::get(\OCP\App\IAppManager::class));
		$server->method('query')->willReturnCallback(static function (string $name): mixed {
			if (str_starts_with($name, 'OCA\\Mcp\\')) {
				throw new \OCP\AppFramework\QueryException('No app binding in the isolated server: ' . $name);
			}
			return Server::get($name);
		});
		$container = new \OC\AppFramework\DependencyInjection\DIContainer('mcp', [], $server);
		$class = \OCA\Mcp\Modules\Talk\Integration\TalkServices::class;
		$automatic = $container->get($class);
		self::initialize($automatic);
		$factories = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(static function (string $id, callable $factory) use (&$factories): void { $factories[$id] = $factory; });
		(new \OCA\Mcp\Modules\Talk\TalkRegistration())->register($context);
		$explicit = $factories[$class]($container);
		foreach (['appManager', 'container', 'userManager'] as $field) {
			$property = new \ReflectionProperty($class, $field);
			self::assertSame($property->getValue($automatic), $property->getValue($explicit), 'Talk automatic versus explicit: ' . $field);
		}
		self::assertSame($container, (new \ReflectionProperty($class, 'container'))->getValue($explicit));
		self::assertSame(Server::get(\OCP\IUserManager::class), (new \ReflectionProperty($class, 'userManager'))->getValue($explicit));
	}
}
