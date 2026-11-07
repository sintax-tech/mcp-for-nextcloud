<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Compat;

use OCA\Mcp\Tests\Integration\Support\ScenePolicySetup;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

/** Proves native scene setup cannot change the pre-upgrade service snapshot or its actors. */
final class ScenePolicyAssertions {
	/**
	 * @param TestCase $test Existing compatibility test supplying official interface doubles.
	 * @return void Absent, enabled and disabled switches survive both scene setup variants exactly.
	 */
	public static function unchanged(TestCase $test): void {
		foreach ([null, '0', '1'] as $service) {
			$data = new InMemoryConfig();
			$data->app = $service === null ? [] : ['mcp' => ['service_enabled' => $service]];
			$data->user['mcp-it-snap-0']['mcp'] = ['eligible' => '1', 'connected' => '1'];
			$before = $data->app;
			$snapshot = $data->user['mcp-it-snap-0'];
			$config = $data->mock($test);
			$policy = InMemoryConfig::policy($config, new InMemoryOAuthStore());
			ScenePolicySetup::actors($policy, ['mcp-it-forms-owner']);
			ScenePolicySetup::actors($policy, ['mcp-it-polls-owner'], 'polls');
			Assert::assertSame($before, $data->app, 'Provisioning must preserve the switch bytes and absence');
			Assert::assertSame($snapshot, $data->user['mcp-it-snap-0']);
			Assert::assertSame(0, $data->authEpoch, 'Provisioning must not change the global credential epoch');
			Assert::assertTrue($policy->eligible('mcp-it-forms-owner'));
			Assert::assertTrue($policy->connected('mcp-it-forms-owner'));
			Assert::assertTrue($policy->granted('mcp-it-polls-owner', 'polls', 'read'));
		}
	}
}
