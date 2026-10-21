<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Receipts;

use Closure;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Access\Policy\PolicyUsers;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use OCA\Mcp\Access\Policy\Transactions\PolicyConflict;
use OCA\Mcp\Access\Policy\Events\PolicyEventPayload;
use OCA\Mcp\Access\Policy\Receipts\ReceiptStore;
use OCA\Mcp\Access\Policy\Receipts\ReceiptNotFound;
use OCA\Mcp\Access\Grants\Commands\GrantMutations;
use OCA\Mcp\Access\Grants\Commands\GrantInheritance;
use OCA\Mcp\Access\ResourceScope\ScopeMutations;
use OCA\Mcp\Access\ResourceScope\ScopeActivation;
use OCA\Mcp\Access\ResourceScope\ScopeInactive;
use OCA\Mcp\Db\PolicyEventsMapper;

final class ReceiptUndo {
    public function __construct(private PolicyTransaction $transaction, private ReceiptStore $receipts, private GrantMutations $mutations, private GrantInheritance $inheritance, private PolicyUsers $users, private ScopeMutations $scopes, private ScopeActivation $activation) {}

    public function restoreReceipt(string $receiptId, string $expectedRevision, string $actor, ?Closure $inheritance = null): array {
        DecimalCounter::validate($expectedRevision);
        $receipt = $this->receipts->read($receiptId, $actor);
        if ($receipt['revision'] !== $expectedRevision) {
            throw new PolicyConflict();
        }
        $cells = array_map(static fn (array $change): array => ['uid' => $change['uid'], 'key' => $change['key'], 'value' => $change['before']], $receipt['changes']);
        $uids = array_values(array_unique(array_column($cells, 'uid')));
        // Scope cells go back to the scope tables, grant cells to the preferences.
        $scopeCells = array_values(array_filter($cells, static fn (array $cell): bool => PolicyEventPayload::scopeModule($cell['key']) !== null));
        $grantCells = array_values(array_filter($cells, static fn (array $cell): bool => PolicyEventPayload::scopeModule($cell['key']) === null));
        foreach ($scopeCells as $cell) {
            if (!$this->activation->isActive((string)PolicyEventPayload::scopeModule($cell['key']))) {
                throw new ScopeInactive('Resource scope is not active for this module');
            }
        }
        $changed = 0;
        $result = $this->transaction->runAdmin($expectedRevision, $uids, function () use ($uids, $grantCells, $inheritance): array {
            foreach ($uids as $uid) {
                try {
                    $this->users->assertKnown($uid);
                } catch (\InvalidArgumentException) {
                    // Writing preferences back for a deleted account would leave orphans; the state moved on.
                    throw new PolicyConflict();
                }
            }
            return $this->inheritance->observe($grantCells, $inheritance);
        }, function (array $inherited, PolicyAttempt $attempt) use ($grantCells, $scopeCells, $actor, $receiptId, &$changed): bool {
            $current = $attempt->state();
            $outbox = $attempt->outbox();
            $changes = $grantCells === [] ? [] : $this->mutations->apply($attempt, $grantCells, $inherited);
            $revision = DecimalCounter::increment($current['revision']);
            foreach ($scopeCells as $cell) {
                $items = PolicyEventPayload::scopeItems($cell['value']);
                $change = $items === false ? throw new PolicyUnavailable('Invalid stored receipt')
                    : $this->scopes->write($attempt, $cell['uid'], (string)PolicyEventPayload::scopeModule($cell['key']), $items, $revision);
                if ($change !== null) {
                    $changes[] = $change;
                }
            }
            $changed = count($changes);
            if ($changes === []) {
                return false;
            }
            $outbox->add(PolicyEventsMapper::KIND_AUDIT, PolicyEventPayload::audit($actor, PolicyEventPayload::ACTION_UNDO,
                $current['revision'], DecimalCounter::increment($current['revision']), null, $receiptId, $changes));
            return true;
        });
        return ['revision' => $result['revision'], 'changed' => $changed];
    }

    public function receiptUsers(string $receiptId, string $actor): ?array {
        try {
            $receipt = $this->receipts->read($receiptId, $actor, false);
        } catch (ReceiptNotFound|PolicyUnavailable) {
            return null;
        }
        return array_values(array_unique(array_column($receipt['changes'], 'uid')));
    }
}
