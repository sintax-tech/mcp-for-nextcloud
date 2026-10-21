<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\ResourceScope;

use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Access\Policy\PolicyUsers;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Policy\Transactions\PolicyAttempt;
use OCA\Mcp\Access\Policy\Events\PolicyEventPayload;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Db\PolicyEventsMapper;

/** Validates native resource choices and commits one scope change with its receipt and audit. */
final class ResourceScopeWriter {
    public function __construct(
        private PolicyTransaction $transaction,
        private PolicyUsers $users,
        private ScopeActivation $activation,
        private NativeResourceEnumerator $resources,
        private ScopeMutations $mutations,
    ) {}

    /** @param list<string>|null $items null means unrestricted; an empty list denies all resources. */
    public function setResourceScope(string $expectedRevision, string $actor, string $uid, string $module, ?array $items): array {
        if (!PolicyEventPayload::validUid($actor) || !in_array($module, ResourceScopeMapper::MODULES, true)) {
            throw new \InvalidArgumentException('Invalid command');
        }
        if (!$this->activation->isActive($module)) {
            throw new ScopeInactive('Resource scope is not active for this module');
        }
        $items = $items === null ? null : self::scopeItems($items);
        $resources = $this->resources;
        $receiptId = null;
        $result = $this->transaction->runAdmin($expectedRevision, [$uid], function () use ($uid, $module, $items, $resources): void {
            $this->users->assertKnown($uid);
            $missing = $items === null ? [] : $resources->inaccessible($uid, $module, $items);
            if ($missing !== []) {
                usort($missing, DecimalCounter::compare(...));
                throw new ScopeItemsInaccessible($missing);
            }
        }, function ($snapshot, PolicyAttempt $attempt) use ($actor, $uid, $module, $items, &$receiptId): bool {
            $current = $attempt->state();
            $outbox = $attempt->outbox();
            $receiptId = null;
            $revision = DecimalCounter::increment($current['revision']);
            $change = $this->mutations->write($attempt, $uid, $module, $items, $revision);
            if ($change === null) {
                return false;
            }
            $receiptId = $outbox->add(PolicyEventsMapper::KIND_RECEIPT, PolicyEventPayload::receipt($actor, PolicyEventPayload::ACTION_SCOPE, $current['revision'], $revision, [$change]));
            $outbox->add(PolicyEventsMapper::KIND_AUDIT, PolicyEventPayload::audit($actor, PolicyEventPayload::ACTION_SCOPE, $current['revision'], $revision, $receiptId, null, []));
            return true;
        });
        return ['revision' => $result['revision'], 'changed' => $result['changed'], 'receiptId' => $receiptId];
    }

    /** @return list<string> canonical, distinct resource ids in decimal order */
    private static function scopeItems(array $items): array {
        if (!array_is_list($items)) {
            throw new \InvalidArgumentException('Invalid command');
        }
        $unique = [];
        foreach ($items as $id) {
            try {
                if (!is_string($id) || DecimalCounter::validate($id, DecimalCounter::MAX) === '0') {
                    throw new \InvalidArgumentException('Invalid command');
                }
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException('Invalid command', 0, $e);
            }
            $unique[$id] = true;
            if (count($unique) > ResourceScopeMapper::MAX_ITEMS) {
                throw new ScopeTooLarge('Too many resources in one scope');
            }
        }
        $ids = array_map('strval', array_keys($unique));
        usort($ids, DecimalCounter::compare(...));
        return $ids;
    }
}
