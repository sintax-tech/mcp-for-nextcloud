<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Activity;

use OCA\Mcp\Modules\Activity\Integration\ActivityGateway;
use OCA\Mcp\Modules\Activity\Integration\ActivitySurface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;

/** Actual pinned classes, isolated per test so NC34 and NC35 cannot contaminate one another. */
final class ActivityNativeFixture {
	/**
	 * @param string $major source pin
	 * @return void load only the three pinned native declarations
	 */
	public static function load(string $major = '34'): void {
		if ($major === '35') require_once __DIR__ . '/../../../fixtures/activity-native/35/SearchCriteria.php';
		foreach (['GroupHelper', 'UserSettings', 'Data'] as $class) require_once __DIR__ . '/../../../fixtures/activity-native/' . $major . '/' . $class . '.php';
	}
	/**
	 * @param int $id native ID
	 * @param array<string,mixed> $extra native row changes
	 * @return array<string,mixed> a reference-free event
	 */
	public static function row(int $id, array $extra = []): array {
		return array_replace(['activity_id' => $id, 'affecteduser' => 'alice', 'type' => 'mcp_probe', 'user' => 'alice', 'app' => 'mcp', 'timestamp' => 1800000000,
			'object_type' => '', 'object_id' => 0, 'file' => '', 'link' => '', 'subject' => 'private subject', 'message' => 'private message',
			'subjectparams' => '[]', 'messageparams' => '[]'], $extra);
	}
	/**
	 * @param TestCase $test owner of every zero-effect mock
	 * @param callable $page native Data::get substitute, receiving the fresh collector and exact arguments
	 * @param callable|null $lookup native cursor lookup, null supplies an existing event owned by alice
	 * @return ActivityGateway real adapter over strict native mocks
	 */
	public static function gateway(TestCase $test, callable $page, ?callable $lookup = null): ActivityGateway {
		self::load();
		$data = $test->getMockBuilder(ActivitySurface::DATA)->disableOriginalConstructor()->getMock();
		foreach ((new ReflectionClass(ActivitySurface::DATA))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
			if (!in_array($method->getName(), ['__construct', 'get', 'getById'], true)) $data->expects($test::never())->method($method->getName());
		}
		$data->method('get')->willReturnCallback($page);
		$event = $test->getMockBuilder(\OCP\Activity\IEvent::class)->getMock();
		$event->method('getAffectedUser')->willReturn('alice');
		$data->method('getById')->willReturnCallback($lookup ?? static fn () => $event);
		$settings = $test->getMockBuilder(ActivitySurface::SETTINGS)->disableOriginalConstructor()->getMock();
		$container = $test->getMockBuilder(ContainerInterface::class)->getMock();
		$container->method('get')->willReturnCallback(static fn ($id) => match ($id) {
			ActivitySurface::DATA => $data, ActivitySurface::SETTINGS => $settings,
			default => throw new \LogicException('Unexpected service: ' . $id),
		});
		return new ActivityGateway($container, new ActivitySurface());
	}
}
