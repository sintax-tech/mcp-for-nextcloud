<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables;

use OCA\Mcp\Tools\Tables\TablesServices;
use OCA\Mcp\Tools\Tables\TablesUnavailableException;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

require_once __DIR__ . '/Stubs/tables_stubs.php';

/**
 * The Tables services are resolved only for a user with Tables 1.0.0 or later enabled and only when the class is loaded;
 * the container is never asked otherwise.
 */
final class TablesServicesTest extends TestCase {
	/**
	 * Off for the user, too old, an unknown user, or a class the installation does not have: unavailable, and the
	 * container is never asked.
	 *
	 * @return void
	 */
	public function testUnavailableNeverReachesTheContainer(): void {
		foreach ([[false, '2.2.2', true], [true, '0.9.12', true], [true, '2.2.2', false]] as [$enabled, $version, $known]) {
			$container = $this->createMock(ContainerInterface::class);
			$container->expects($this->never())->method('get');
			$services = $this->services($enabled, $version, $known, $container);
			$this->assertFalse($services->isEnabledFor('alice'));
			try {
				$services->tableService('alice');
				$this->fail('expected Tables to be unavailable');
			} catch (TablesUnavailableException) {
			}
		}
		// Enabled and supported, but the TableService class is not loaded (no Tables code in the unit tests).
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$services = $this->services(true, '2.2.2', true, $container);
		$this->assertTrue($services->isEnabledFor('alice'));
		$this->expectException(TablesUnavailableException::class);
		$services->tableService('alice');
	}

	/**
	 * Enabled in a supported version: the node type of a view is the Tables constant.
	 *
	 * @return void
	 */
	public function testTheViewNodeTypeIsTheTablesConstant(): void {
		$this->assertSame(1, $this->services(true, '1.0.0', true, $this->createMock(ContainerInterface::class))->viewNodeType('alice'));
		$this->expectException(TablesUnavailableException::class);
		$this->services(false, '1.0.0', true, $this->createMock(ContainerInterface::class))->viewNodeType('alice');
	}

	/**
	 * The services for a user.
	 *
	 * @param bool $enabled whether Tables is enabled for the user
	 * @param string $version installed Tables version
	 * @param bool $known whether the user exists
	 * @param ContainerInterface $container the container
	 * @return TablesServices the services
	 */
	private function services(bool $enabled, string $version, bool $known, ContainerInterface $container): TablesServices {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(static fn (string $app): bool => $app === 'tables' && $enabled);
		$apps->method('getAppVersion')->willReturn($version);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($known ? $this->createMock(IUser::class) : null);
		return new TablesServices($apps, $container, $users);
	}
}
