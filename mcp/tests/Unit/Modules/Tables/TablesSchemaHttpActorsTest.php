<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use OCA\Mcp\Tests\Integration\Support\ServiceSwitchFixture;
use OCA\Mcp\Tests\Integration\Tables\TablesSchemaHttpActors;
use OCA\Mcp\Tests\Integration\Tables\Write\WriteCellScene;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Reproduces an ineligible reader without relying on another HTTP test to prepare its policy. */
final class TablesSchemaHttpActorsTest extends TestCase {
	/** @return iterable<string, array{?string, bool}> Both outcomes restore absent, denied and allowed preferences. */
	public static function states(): iterable {
		foreach ([null, '0', '1'] as $raw) {
			foreach ([false, true] as $failure) {
				yield ($raw ?? 'absent') . ($failure ? '-failed' : '-passed') => [$raw, $failure];
			}
		}
	}

	/**
	 * @param string|null $raw Original switch and actor preference, including absence.
	 * @param bool $failure Whether a synthetic consent/body failure occurs before cleanup.
	 * @return void Both actors become admissible and all touched preferences return to their original bytes.
	 */
	#[DataProvider('states')]
	public function testIndependentAccessAndRestoration(?string $raw, bool $failure): void {
		$data = new InMemoryConfig();
		$data->app = ['mcp' => ['unrelated' => 'keep']];
		if ($raw !== null) {
			$data->app['mcp']['service_enabled'] = $raw;
		}
		$keys = ['eligible', 'connected', 'grant_tables_read', 'grant_tables_schema', 'grant_tables_share'];
		foreach ([WriteCellScene::OWNER, WriteCellScene::READER] as $uid) {
			$data->user[$uid]['mcp'] = ['unrelated' => 'keep'];
			if ($raw !== null) {
				foreach ($keys as $key) {
					$data->user[$uid]['mcp'][$key] = $raw;
				}
			}
		}
		$before = $data->user;
		$appBefore = $data->app;
		$config = $data->mock($this);
		$config->method('getUserKeys')->willReturnCallback(static fn (string $uid, string $app): array => array_keys($data->user[$uid][$app] ?? []));
		$policy = InMemoryConfig::policy($config, new InMemoryOAuthStore());
		$service = new ServiceSwitchFixture($config, $data->appConfig($this), $policy);
		$actors = new TablesSchemaHttpActors($policy, $config);
		try {
			$service->enable();
			if ($raw !== '1') {
				self::assertTrue($policy->globalEnabled());
				self::assertNull($policy->admission(WriteCellScene::READER, false), 'Even with the service on, consent refuses an ineligible reader');
			}
			$actors->enable();
			$actors->enable();
			foreach ([WriteCellScene::OWNER, WriteCellScene::READER] as $uid) {
				self::assertSame([], $policy->admission($uid, false), 'Consent is admitted independently');
				self::assertTrue($policy->canConnect($uid), 'Exchange and HTTP requests have a connection');
				self::assertTrue($policy->granted($uid, 'tables', 'read'));
			}
			$policy->setGrant(WriteCellScene::OWNER, 'tables', 'schema', true);
			$policy->setGrant(WriteCellScene::OWNER, 'tables', 'share', true);
			if ($failure) {
				throw new RuntimeException('Synthetic failed consent or HTTP assertion');
			}
		} catch (RuntimeException $error) {
			self::assertTrue($failure);
			self::assertSame('Synthetic failed consent or HTTP assertion', $error->getMessage());
		} finally {
			try {
				$actors->restore();
			} finally {
				$service->restore();
			}
		}
		self::assertSame($before, $data->user);
		self::assertSame($appBefore, $data->app);
		self::assertSame(0, $data->authEpoch, 'Restoring the service must not cut global credentials');
		$actors->restore();
		$service->restore();
		self::assertSame($before, $data->user, 'Repeated cleanup is inert');
	}
}
