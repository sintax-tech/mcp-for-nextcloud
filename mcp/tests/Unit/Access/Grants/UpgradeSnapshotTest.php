<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\Grants;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Compat\SnapshotProbe;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use PHPUnit\Framework\TestCase;

/**
 * T12 over the synthetic snapshot of Compat/SnapshotProbe, without any group: every operation of the CATALOG stored
 * as '1', '0' and absent, under every (eligible, connected) pair and every state of the service switch, gets from the
 * branch the answer of 1.0.8, and reading writes nothing (no default materialized, no revision, no revocation).
 *
 * The oracle below transcribes 1.0.8's GrantPolicy (tag v1.0.8): every read is IConfig::getUserValue/getAppValue
 * compared with '1', with '1' as the default of a read outside DENIED_READS and '0' for everything else. The real
 * baseline code is the oracle of the integration test of the same name, on the upgrade cells.
 */
final class UpgradeSnapshotTest extends TestCase {
    public function testEveryOperationInEveryStateGetsThe108AnswerAndReadingWritesNothing(): void {
        $data = new InMemoryConfig();
        $config = $data->mock($this);
        $store = new InMemoryOAuthStore();
        $policy = InMemoryConfig::policy($config, $store);
        $plan = SnapshotProbe::plan(GrantPolicy::CATALOG);
        foreach ($plan as $uid => $values) {
            $data->user[$uid]['mcp'] = array_filter($values, static fn (?string $value): bool => $value !== null);
        }
        $users = $data->user;
        $revision = $data->revision;
        $checked = 0;
        foreach (SnapshotProbe::VALUES as $service) {
            $data->app = $service === null ? [] : ['mcp' => ['service_enabled' => $service]];
            $app = $data->app;
            $matrix = $policy->forUsers(array_keys($plan));
            foreach ($plan as $uid => $values) {
                // One operation per user, as a request reads it (one snapshot), so the doubles stay small.
                $policy->within($uid, function () use ($policy, $matrix, $service, $uid, $values, &$checked): void {
                    $eligible = $values['eligible'] === '1';
                    $connected = $values['connected'] === '1';
                    $this->assertSame($eligible, $policy->eligible($uid), $uid);
                    $this->assertSame($connected, $policy->connected($uid), $uid);
                    $this->assertSame($service === '1' && $eligible && $connected, $policy->canConnect($uid), "$uid service $service");
                    $this->assertSame($eligible, $matrix[$uid]['eligible'], $uid);
                    $this->assertSame($connected, $matrix[$uid]['connected'], $uid);
                    foreach (GrantPolicy::CATALOG as $module => $operations) {
                        foreach ($operations as $operation) {
                            $default = $operation === 'read' && $module !== 'logs' ? '1' : '0';
                            $legacy = ($values["grant_{$module}_{$operation}"] ?? $default) === '1';
                            $this->assertSame($legacy, $policy->granted($uid, $module, $operation), "$uid $module/$operation");
                            $this->assertSame($legacy, $matrix[$uid]['grants'][$module][$operation], "$uid $module/$operation (matrix)");
                            ++$checked;
                        }
                    }
                });
            }
            $legacyEligible = array_keys(array_filter($plan, static fn (array $values): bool => $values['eligible'] === '1'));
            $this->assertSame($legacyEligible, $policy->flaggedUsers(GrantPolicy::ELIGIBLE_KEY));
            $this->assertSame(array_values(array_intersect($legacyEligible, array_keys(array_filter($plan,
                static fn (array $values): bool => $values['connected'] === '1')))), $policy->connectedUsers());
            $this->assertSame($app, $data->app, 'reading materialized an app value');
        }
        $this->assertSame(3 * 9 * array_sum(array_map('count', GrantPolicy::CATALOG)), $checked);
        $this->assertSame($users, $data->user, 'reading materialized a default or changed a stored value');
        $this->assertSame($revision, $data->revision);
        $this->assertSame([], $data->events);
        $this->assertSame([], $data->generations, 'nothing was cut');
    }
}
