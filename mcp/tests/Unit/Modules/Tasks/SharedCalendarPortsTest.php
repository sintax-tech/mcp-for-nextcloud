<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tasks;

use OCA\Mcp\Access\ResourceScope\ScopedCalendarLookup;
use OCA\Mcp\Modules\Calendar\CalendarRegistration;
use OCA\Mcp\Modules\Calendar\Locating\CalendarAccess;
use OCA\Mcp\Modules\Calendar\Locating\CalendarStore;
use OCA\Mcp\Modules\Calendar\Locating\ScopedCalendarAccess;
use OCA\Mcp\Modules\Calendar\Writing\CalendarDav;
use OCA\Mcp\Platform\Dav\CalDavObjects;
use OCA\Mcp\Platform\Dav\CalDavWrite;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;

final class SharedCalendarPortsTest extends TestCase {
	public function testThePortsReturnTheSameCalendarServicesWithoutConstructingAnotherAdapter(): void {
		$factories = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(static function (string $key, callable $factory) use (&$factories): void { $factories[$key] = $factory; });
		(new CalendarRegistration())->register($context);
		$objects = $this->createMock(CalendarStore::class);
		$writes = $this->createMock(CalendarDav::class);
		$lookup = $this->createMock(ScopedCalendarAccess::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::exactly(3))->method('get')->willReturnMap([[CalendarStore::class,$objects], [CalendarDav::class,$writes], [ScopedCalendarAccess::class,$lookup]]);
		self::assertSame($objects, $factories[CalDavObjects::class]($container));
		self::assertSame($writes, $factories[CalDavWrite::class]($container));
		self::assertSame($lookup, $factories[ScopedCalendarLookup::class]($container));
	}

	public function testTheSharedReadPortDoesNotOfferAnUnscopedEnumerationOrNativeAccess(): void {
		$methods = array_map(static fn ($method) => $method->name, (new ReflectionClass(CalDavObjects::class))->getMethods());
		sort($methods);
		self::assertSame(['object','objectByUid','objects'], $methods);
		self::assertFalse(is_a(CalendarAccess::class, ScopedCalendarLookup::class, true));
		self::assertTrue(is_a(ScopedCalendarAccess::class, ScopedCalendarLookup::class, true));
	}
}
