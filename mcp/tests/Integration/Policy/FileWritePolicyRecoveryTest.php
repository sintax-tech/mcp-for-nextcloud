<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Policy;

use OCA\Mcp\Access\FileTypes\FileWritePolicy;
use OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Server;

/** Real core typed-value repair and rollback over the same official database used by IAppConfig. */
final class FileWritePolicyRecoveryTest extends IntegrationTestCase {
	private bool $seeded = false;

	/** Seed an integer at the otherwise absent policy key in this disposable fixture only. */
	protected function setUp(): void {
		parent::setUp();
		$config = Server::get(IAppConfig::class);
		$config->clearCache(false);
		self::assertFalse($config->hasKey('mcp', FileWritePolicy::KEY, null));
		$config->setValueInt('mcp', FileWritePolicy::KEY, 7, false);
		$this->seeded = true;
	}

	/** Restore the fixture's absence after committing/rolling back the official transaction. */
	protected function tearDown(): void {
		parent::tearDown();
		if ($this->seeded) {
			$config = Server::get(IAppConfig::class);
			$config->deleteKey('mcp', FileWritePolicy::KEY);
			$config->clearCache(false);
		}
	}

	/** Save repairs the type and another real DB session sees no absent row during the delete/set window. */
	public function testAdminSaveRecoversWrongCoreTypeAtomically(): void {
		$core = Server::get(IAppConfig::class);
		$observer = $this->openIndependentConnection();
		$proxy = $this->createMock(IAppConfig::class);
		$proxy->method('clearCache')->willReturnCallback(static fn ($reload) => $core->clearCache($reload));
		$proxy->method('hasKey')->willReturnCallback(static fn ($app, $key, $lazy) => $core->hasKey($app, $key, $lazy));
		$proxy->method('getValueString')->willReturnCallback(static fn ($app, $key, $default, $lazy) => $core->getValueString($app, $key, $default, $lazy));
		$proxy->method('setValueString')->willReturnCallback(static fn ($app, $key, $value, $lazy) => $core->setValueString($app, $key, $value, $lazy));
		$proxy->method('deleteKey')->willReturnCallback(function ($app, $key) use ($core, $observer): void {
			self::assertTrue($this->official()->inTransaction());
			$core->deleteKey($app, $key);
			$qb = $observer->getQueryBuilder();
			$value = $qb->select('configvalue')->from('appconfig')
				->where($qb->expr()->eq('appid', $qb->createNamedParameter('mcp')))
				->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter(FileWritePolicy::KEY)))
				->executeQuery()->fetchOne();
			self::assertSame('7', (string)$value, 'Another session must not observe absent = off during repair.');
		});
		$policy = new FileWritePolicy($proxy, $this->official());
		$saved = $policy->save('allowlist', ['txt'], false);
		self::assertSame($saved, $policy->current());
		self::assertFalse($this->official()->inTransaction());
	}

	/** A real rollback restores the wrongly typed value, so D9 still refuses rather than treating absence as off. */
	public function testFailedCommitPreservesD9WithTheRealCoreStore(): void {
		$db = $this->official();
		$proxy = $this->createMock(IDBConnection::class);
		$proxy->method('inTransaction')->willReturnCallback(static fn (): bool => $db->inTransaction());
		$proxy->method('beginTransaction')->willReturnCallback(static fn () => $db->beginTransaction());
		$proxy->method('rollBack')->willReturnCallback(static fn () => $db->rollBack());
		$proxy->method('commit')->willThrowException(new \RuntimeException('controlled failure before commit'));
		$config = Server::get(IAppConfig::class);
		$policy = new FileWritePolicy($config, $proxy);
		try {
			$policy->save('allowlist', ['txt'], false);
			self::fail('Failed commit reported success');
		} catch (FileWritePolicyUnavailable) {
			self::assertFalse($db->inTransaction());
		}
		$config->clearCache(false);
		self::assertSame(7, $config->getValueInt('mcp', FileWritePolicy::KEY, 0, false));
		$this->expectException(FileWritePolicyUnavailable::class);
		$policy->current();
	}
}
