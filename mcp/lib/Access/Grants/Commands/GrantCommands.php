<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Grants\Commands;

use Closure;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Access\Policy\PolicyLimits;
use OCA\Mcp\Access\Policy\PolicyUsers;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use OCA\Mcp\Access\Policy\Events\PolicyEventPayload;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Grants\Commands\GrantMutations;
use OCA\Mcp\Access\Grants\Commands\GrantInheritance;
use OCA\Mcp\Db\PolicyEventsMapper;

final class GrantCommands {
    public function __construct(private PolicyTransaction $transaction, private GrantMutations $mutations, private GrantInheritance $inheritance, private PolicyUsers $users) {}

    public function setEligible(string $uid, bool $enabled): void {
        $this->change($uid, GrantPolicy::ELIGIBLE_KEY, $enabled ? '1' : '0');
    }

    public function setConnected(string $uid, bool $enabled): void {
        $this->change($uid, GrantPolicy::CONNECTED_KEY, $enabled ? '1' : '0');
    }

    public function setGrant(string $uid, string $module, string $operation, bool $enabled): void {
        if (!GrantPolicy::inCatalog($module, $operation)) {
            throw new \InvalidArgumentException('Unknown module or operation');
        }
        $this->change($uid, 'grant_' . $module . '_' . $operation, $enabled ? '1' : '0');
    }

    public function resetOverride(string $uid, string $module, string $operation): void {
        if (!GrantPolicy::inCatalog($module, $operation)) {
            throw new \InvalidArgumentException('Unknown module or operation');
        }
        $this->change($uid, 'grant_' . $module . '_' . $operation, null);
    }

    public function applyCells(string $expectedRevision, string $actor, string $action, array $cells, ?Closure $inheritance = null): array {
        if (!in_array($action, PolicyEventPayload::GRANT_ACTIONS, true) || !PolicyEventPayload::validUid($actor)) {
            throw new \InvalidArgumentException('Invalid command');
        }
        $cells = self::cells($cells);
        $uids = array_values(array_unique(array_column($cells, 'uid')));
        $receiptId = null;
        $changed = 0;
        $result = $this->transaction->runAdmin($expectedRevision, $uids, function () use ($uids, $cells, $inheritance): array {
            foreach ($uids as $uid) {
                $this->users->assertKnown($uid);
            }
            return $this->inheritance->observe($cells, $inheritance);
        }, function (array $inherited, PolicyAttempt $attempt) use ($cells, $actor, $action, &$receiptId, &$changed): bool {
            $current = $attempt->state();
            $outbox = $attempt->outbox();
            $changes = $this->mutations->apply($attempt, $cells, $inherited);
            $changed = count($changes);
            $receiptId = null;
            if ($changes === []) {
                return false;
            }
            $revision = DecimalCounter::increment($current['revision']);
            $receiptId = $outbox->add(PolicyEventsMapper::KIND_RECEIPT, PolicyEventPayload::receipt($actor, $action, $current['revision'], $revision, $changes));
            $outbox->add(PolicyEventsMapper::KIND_AUDIT, PolicyEventPayload::audit($actor, $action, $current['revision'], $revision, $receiptId, null, []));
            return true;
        });
        return ['revision' => $result['revision'], 'changed' => $changed, 'receiptId' => $receiptId];
    }

    private function change(?string $uid, string $key, ?string $value): void {
        $uids = $uid === null ? [] : [$uid];
        $this->transaction->runInternal($uids, function () use ($uid): void {
            if ($uid !== null) {
                $this->users->assertKnown($uid);
            }
        }, fn ($snapshot, PolicyAttempt $attempt): bool => $this->mutations->write($attempt, $uid, $key, $value) !== null);
    }

    private static function cells(array $cells): array {
        if ($cells === [] || !array_is_list($cells)) {
            throw new \InvalidArgumentException('Invalid command');
        }
        $distinct = [];
        $uids = [];
        foreach ($cells as $cell) {
            if (!is_array($cell) || !is_string($cell['uid'] ?? null) || !is_string($cell['key'] ?? null)
                || !array_key_exists('value', $cell) || !in_array($cell['value'], ['1', '0', null], true)
                || !PolicyEventPayload::validUid($cell['uid']) || !PolicyEventPayload::validUserKey($cell['key'])) {
                throw new \InvalidArgumentException('Invalid command');
            }
            $id = hash('sha256', $cell['uid'] . "\0" . $cell['key']);
            if (array_key_exists($id, $distinct)) {
                if ($distinct[$id]['value'] !== $cell['value']) {
                    throw new \InvalidArgumentException('Invalid command');
                }
                continue;
            }
            $distinct[$id] = ['uid' => $cell['uid'], 'key' => $cell['key'], 'value' => $cell['value']];
            $uids[$cell['uid']] = true;
            if (count($distinct) > PolicyLimits::MAX_CELLS || count($uids) > PolicyLimits::MAX_UIDS) {
                throw new \InvalidArgumentException('Invalid command');
            }
        }
        return array_values($distinct);
    }
}
