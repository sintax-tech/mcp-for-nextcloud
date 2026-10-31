<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Smoke;

use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;
use OCP\ServerVersion;

/** The suite runs against the cell the workflow announced, with the app installed by the server itself. */
final class HarnessEnvironmentTest extends IntegrationTestCase {
	public function testRunsAgainstTheNextcloudVersionAndDatabaseOfTheCell(): void {
		$major = Server::get(ServerVersion::class)->getMajorVersion();
		$this->assertSame((int)getenv('MCP_IT_NC'), $major);
		$this->assertGreaterThanOrEqual(32, $major);
		$this->assertLessThanOrEqual(35, $major);

		$dbType = Server::get(IConfig::class)->getSystemValueString('dbtype');
		$expected = ['mariadb' => ['mysql', IDBConnection::PLATFORM_MARIADB], 'pgsql' => ['pgsql', IDBConnection::PLATFORM_POSTGRES],
			'sqlite' => ['sqlite3', IDBConnection::PLATFORM_SQLITE]][$this->cellDatabase()];
		$this->assertSame($expected[0], $dbType);
		$this->assertSame($expected[1], $this->official()->getDatabaseProvider(true));
	}

	public function testAppIsInstalledAndMigratedThroughTheNativeInstaller(): void {
		$expected = (string)getenv('MCP_IT_EXPECTED_VERSION');
		$this->assertNotSame('', $expected);
		$appManager = Server::get(IAppManager::class);
		$this->assertTrue($appManager->isEnabledForAnyone('mcp'));
		$this->assertSame($expected, $appManager->getAppVersion('mcp'));
		$this->assertSame($expected, Server::get(IAppConfig::class)->getValueString('mcp', 'installed_version'));
		// Created by the B1 migration, which only runs when the server installs or upgrades the app.
		$this->assertTrue($this->official()->tableExists('mcp_group_grants'));
	}

	/**
	 * The single policy state row is there after a fresh install and after an upgrade alike. The server runs no
	 * migration postSchemaChange() when it installs an app on an empty instance, so this cell proves the install
	 * path the declared step exists for.
	 */
	public function testPolicyStateIsSeededByTheServerOnInstallAndOnUpgrade(): void {
		$this->assertTrue($this->official()->tableExists('mcp_policy_state'));
		$this->assertSame(['revision', 'lock_seq', 'auth_epoch'], array_keys(Server::get(PolicyStateMapper::class)->state()));
	}

	public function testFixtureAccountsExist(): void {
		$users = Server::get(IUserManager::class);
		$groups = Server::get(IGroupManager::class);
		$this->assertTrue($users->userExists(Fixtures::ALICE));
		$this->assertTrue($users->userExists(Fixtures::BOB));
		$this->assertTrue($groups->groupExists(Fixtures::STAFF));
		$this->assertTrue($groups->groupExists(Fixtures::EMPTY));
		$this->assertTrue($groups->isInGroup(Fixtures::ALICE, Fixtures::STAFF));
		$this->assertFalse($groups->isInGroup(Fixtures::BOB, Fixtures::STAFF));
		$this->assertSame([], $groups->get(Fixtures::EMPTY)?->getUsers());
	}
}
