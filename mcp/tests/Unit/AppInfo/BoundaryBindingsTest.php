<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\AppInfo;

use OCA\Mcp\Access\Inspection\CalendarAccessExplainer;
use OCA\Mcp\Access\ResourceScope\NativeCalendars;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Modules\Calendar\NativeCalendarsAdapter;
use OCA\Mcp\Platform\FileAccess\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\WriteVisibility;
use OCA\Mcp\Modules\Calendar\Locating\CalendarAccess;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Required boundary services resolve the registered implementation and reject missing or invalid bindings. */
final class BoundaryBindingsTest extends TestCase {
	/** @return void The visibility binding preserves the very same plain guard and its virtual dispatch. */
	public function testVisibilityFactoryPreservesTheGuardIdentity(): void {
		$guard = $this->createMock(VisibilityGuard::class);
		self::assertSame($guard, BoundaryFactories::resolve($this, WriteVisibility::class, $guard));
	}

	/** @return void The calendar factory resolves the native adapter as the Access-owned port. */
	public function testCalendarFactoryResolvesTheAdapter(): void {
		$adapter = new NativeCalendarsAdapter($this->createMock(CalendarAccess::class));
		self::assertSame($adapter, BoundaryFactories::resolve($this, NativeCalendars::class, $adapter));
	}

	/** @return void A wrong implementation cannot silently enter either registered boundary. */
	public function testWrongBindingsFailForBothPorts(): void {
		foreach ([WriteVisibility::class, NativeCalendars::class] as $key) {
			try {
				BoundaryFactories::resolve($this, $key, new \stdClass());
				self::fail('Wrong boundary implementation accepted: ' . $key);
			} catch (\TypeError $error) {
				self::assertStringContainsString($key, $error->getMessage());
			}
		}
	}

	/** @return void Native autowiring has neither a nullable fallback nor a default at either boundary. */
	public function testBothConsumersRequireTheInterfaceWithoutFallback(): void {
		foreach ([LockAwareWrite::class => 'visibility', CalendarAccessExplainer::class => 'native'] as $class => $name) {
			$parameters = (new ReflectionClass($class))->getConstructor()->getParameters();
			$matching = array_values(array_filter($parameters, static fn ($parameter): bool => $parameter->getName() === $name));
			self::assertCount(1, $matching);
			self::assertFalse($matching[0]->allowsNull());
			self::assertFalse($matching[0]->isDefaultValueAvailable());
		}
	}
}
