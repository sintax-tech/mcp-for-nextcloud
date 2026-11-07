<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Compat;

use OCA\Mcp\Tests\Integration\Support\ServiceSwitchFixture;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Exercises independent HTTP-test scopes from every switch state, including a failed test body. */
final class ServiceSwitchAssertions {
	/**
	 * @param TestCase $test Existing upgrade contract identity supplying Nextcloud interface doubles.
	 * @return void Every scope enables requests then restores the original switch without a global credential cut.
	 */
	public static function roundTrip(TestCase $test): void {
		foreach ([null, '0', '1', ''] as $before) {
			$data = new InMemoryConfig();
			$data->app = ['mcp' => ['unrelated' => 'keep']];
			if ($before !== null) {
				$data->app['mcp']['service_enabled'] = $before;
			}
			$original = $data->app;
			$config = $data->mock($test);
			$policy = InMemoryConfig::policy($config, new InMemoryOAuthStore());
			// Nine independent HTTP classes; alternate success/failure to model arbitrary execution order.
			for ($i = 0; $i < 9; $i++) {
				$scope = new ServiceSwitchFixture($config, $data->appConfig($test), $policy);
				try {
					$scope->enable();
					Assert::assertTrue($policy->globalEnabled());
					if ($i % 2 === 1) {
						throw new RuntimeException('Synthetic failed HTTP assertion');
					}
				} catch (RuntimeException $error) {
					Assert::assertSame('Synthetic failed HTTP assertion', $error->getMessage());
				} finally {
					$scope->restore();
				}
				Assert::assertSame($original, $data->app, 'Each tearDown restores bytes and absence before the next setUp');
				Assert::assertSame($before === '1', $policy->globalEnabled());
				Assert::assertSame(0, $data->authEpoch, 'Raw restoration must not issue a service-off credential cut');
			}
		}
	}
}
