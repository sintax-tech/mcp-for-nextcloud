<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\AppInfo;

use OCA\Mcp\AppInfo\Application;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionMethod;

/** Executes the actual application registrations with a test-owned dependency container. */
final class BoundaryFactories {
	/**
	 * @param TestCase $test Owner of PHPUnit doubles.
	 * @param class-string $key Registered interface to resolve.
	 * @param object $dependency Object returned for the implementation dependency.
	 * @return object Actual typed factory result, without replacing the registration under test.
	 * @throws \TypeError When the registered interface receives an unrelated implementation.
	 */
	public static function resolve(TestCase $test, string $key, object $dependency): object {
		$mock = static fn (string $class): object => (new ReflectionMethod($test, 'createMock'))->invoke($test, $class);
		$factories = [];
		$context = $mock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(static function (string $name, callable $factory) use (&$factories): void {
			$factories[$name] = $factory;
		});
		(new ReflectionClass(Application::class))->newInstanceWithoutConstructor()->register($context);
		$container = $mock(ContainerInterface::class);
		$container->method('get')->willReturn($dependency);
		return $factories[$key]($container);
	}
}
