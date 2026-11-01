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
		self::assertCount(13, Application::MODULES);
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
}
