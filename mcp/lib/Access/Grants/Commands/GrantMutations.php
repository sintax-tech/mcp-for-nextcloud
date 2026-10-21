<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Grants\Commands;

use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Grants\Commands\GrantInheritance;
use OCA\Mcp\Access\Grants\Groups\LifecycleHolds;
use OCA\Mcp\Access\Policy\Resolution\GrantUnknown;
use OCA\Mcp\OAuth\Revocation\CredentialCuts;
use OCP\IConfig;
use OCP\IAppConfig;

final class GrantMutations {
    public function __construct(private IConfig $config, private IAppConfig $appConfig, private CredentialCuts $cuts, private LifecycleHolds $holds) {}

    public function apply(PolicyAttempt $attempt, array $cells, array $inherited = []): array {
        $attempt->assertActive();
        $byKey = [];
        foreach ($cells as $cell) {
            $byKey[$cell['key']][] = $cell['uid'];
        }
        $current = [];
        foreach ($byKey as $key => $uids) {
            $current[$key] = $this->config->getUserValueForUsers(GrantPolicy::APP, (string)$key, $uids);
        }
        $changes = [];
        foreach ($cells as $cell) {
            $values = $current[$cell['key']];
            $before = array_key_exists($cell['uid'], $values) ? (string)$values[$cell['uid']] : null;
            $change = $this->write($attempt, $cell['uid'], $cell['key'], $cell['value'], $before,
                $cell['key'] === GrantPolicy::ELIGIBLE_KEY ? ($inherited[hash('sha256', $cell['uid'])] ?? null) : null);
            if ($change !== null) {
                $changes[] = $change;
            }
        }
        return $changes;
    }

    public function write(PolicyAttempt $attempt, ?string $uid, string $key, ?string $value, string|false|null $before = false, ?array $inherited = null): ?array {
        $attempt->assertActive();
        if ($before === false) {
            // Batch reads preserve presence and legacy MIXED values. Never materialize an inherited default.
            if ($uid === null) {
                $before = $this->appConfig->hasKey(GrantPolicy::APP, $key) ? $this->config->getAppValue(GrantPolicy::APP, $key) : null;
            } else {
                $values = $this->config->getUserValueForUsers(GrantPolicy::APP, $key, [$uid]);
                $before = array_key_exists($uid, $values) ? (string)$values[$uid] : null;
            }
        }
        $changed = $before !== $value;
        $reset = $changed && $uid !== null && $key === GrantPolicy::ELIGIBLE_KEY && $before === '1' && $value === null && $inherited !== null;
        if ($reset && $inherited['state'] === GrantInheritance::UNKNOWN) {
            // Before any write: the command rolls back whole, nothing is cut (503).
            throw new GrantUnknown('Inherited eligibility is unknown');
        }
        if ($reset && $inherited['state'] === GrantInheritance::ELIGIBLE) {
            // Before the write too: a group that left active since the external phase replays the whole attempt.
            $this->holds->hold($attempt, $inherited['lifecycles'] ?? []);
        }
        if ($changed) {
            if ($uid === null) {
                $this->config->setAppValue(GrantPolicy::APP, $key, (string)$value);
            } elseif ($value === null) {
                $this->config->deleteUserValue($uid, GrantPolicy::APP, $key);
            } else {
                $this->config->setUserValue($uid, GrantPolicy::APP, $key, $value);
            }
        }
        if ($key === GrantPolicy::SERVICE_KEY && $value === '0') {
            // Every explicit disable moves the epoch, as 1.0.8 deleted everything on every disable: an off value
            // written outside the app must not leave credentials that come back when the service is switched on.
            $this->cuts->all($attempt);
        } elseif ($reset) {
            if ($inherited['state'] === GrantInheritance::LOST) {
                $this->cuts->user($attempt, $uid);
            }
        } elseif ($uid !== null && (($key === GrantPolicy::ELIGIBLE_KEY && ($value === '0' || ($changed && $value !== '1')))
            || ($key === GrantPolicy::CONNECTED_KEY && $value === '0'))) {
            $this->cuts->user($attempt, $uid);
        }
        return $changed ? ['uid' => $uid, 'key' => $key, 'before' => $before, 'after' => $value] : null;
    }
}
