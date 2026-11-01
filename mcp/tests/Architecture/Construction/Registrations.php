<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Architecture\Construction;

use OCA\Mcp\AppInfo\Application;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use LogicException;

/** Captures every registerService call made by the actual composition root and module registrations. */
final class Registrations {
	/** @param TestCase $test Owner of the registration-context double. @return array<string,callable> All factories, in source registration order. */
	public static function capture(TestCase $test): array {
		$factories = [];
		$context = (new ReflectionMethod($test, 'createMock'))->invoke($test, IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(static function (string $key, callable $factory) use (&$factories): void {
			if (isset($factories[$key])) { throw new LogicException('Duplicate factory: ' . $key); }
			$factories[$key] = $factory;
		});
		(new ReflectionClass(Application::class))->newInstanceWithoutConstructor()->register($context);
		if ($factories === []) { throw new LogicException('Application registered no services'); }
		return $factories;
	}
}
