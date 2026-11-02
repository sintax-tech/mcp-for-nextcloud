<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\AppInfo;

use OC\AppFramework\DependencyInjection\DIContainer;
use OC\ServerContainer;
use OCA\Mcp\Access\Inspection\CalendarAccessExplainer;
use OCA\Mcp\Access\Policy\PolicyResolver;
use OCA\Mcp\Access\ResourceScope\NativeCalendars;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Modules\Calendar\NativeCalendarsAdapter;
use OCA\Mcp\Platform\FileAccess\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\WriteVisibility;
use OCP\App\IAppManager;
use OCP\AppFramework\QueryException;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use ReflectionClass;
use ReflectionProperty;

/** Native Nextcloud resolution requires both explicit boundary registrations on every supported server. */
final class BoundaryBindingsTest extends TestCase {
	/** @return void Real app registrations resolve the same plain visibility guard and the native calendar adapter. */
	public function testRegisteredBoundariesResolveThroughTheRealServer(): void {
		$visibility = Server::get(WriteVisibility::class);
		self::assertSame(Server::get(VisibilityGuard::class), $visibility);
		self::assertSame(VisibilityGuard::class, $visibility::class);
		$locks = Server::get(LockAwareWrite::class);
		self::initialize($locks);
		self::assertSame($visibility, (new ReflectionProperty($locks, 'visibility'))->getValue($locks));
		self::assertInstanceOf(NativeCalendarsAdapter::class, Server::get(NativeCalendars::class));
		self::initialize(Server::get(CalendarAccessExplainer::class));
		self::assertInstanceOf(\OCA\Mcp\Modules\Notes\Reading\NoteResourceReader::class, Server::get(\OCA\Mcp\Mcp\Resources\NoteResource::class));
	}

	/** @return void A fresh native app container without either registration fails at the interface, never with null. */
	public function testMissingBoundaryRegistrationsFailOnFirstResolution(): void {
		// Isolate app registrations from the running server while retaining real native core services.
		$server = $this->createMock(ServerContainer::class);
		$server->method('get')->with(IAppManager::class)->willReturn(Server::get(IAppManager::class));
		$server->method('query')->willReturnCallback(static function (string $name): mixed {
			if (str_starts_with($name, 'OCA\\Mcp\\') || in_array($name, ['visibility', 'native'], true)) {
				throw new QueryException('No app binding in the isolated server: ' . $name);
			}
			return Server::get($name);
		});
		foreach ([LockAwareWrite::class => WriteVisibility::class, CalendarAccessExplainer::class => NativeCalendars::class] as $class => $port) {
			$container = new DIContainer('mcp', [], $server);
			$container->registerService(PolicyResolver::class, static fn (): PolicyResolver => Server::get(PolicyResolver::class));
			try {
				self::initialize($container->get($class));
				self::fail('Missing boundary accepted: ' . $port);
			} catch (ContainerExceptionInterface $error) {
				self::assertStringContainsString($port, $error->getMessage());
			}
		}
	}

	/**
	 * @param object $service Native service, possibly a PHP 8.4+ lazy ghost.
	 * @return void Force constructor resolution without invoking an operation or mutating app state.
	 */
	private static function initialize(object $service): void {
		$reflection = new ReflectionClass($service);
		if (method_exists($reflection, 'initializeLazyObject')) {
			$reflection->initializeLazyObject($service);
		}
	}
}
