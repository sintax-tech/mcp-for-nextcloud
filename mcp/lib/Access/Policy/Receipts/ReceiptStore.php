<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Receipts;

use OCA\Mcp\Access\Policy\PolicyLimits;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\Events\PolicyEventPayload;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DatabaseException;

/** Validates persisted receipt payloads and enforces ownership before expiry. */
final class ReceiptStore {
    public function __construct(private PolicyEventsMapper $events, private ITimeFactory $time) {}

    /** @return array{revision:string, changes:list<array{uid:string, key:string, before:?string, after:?string}>} */
    public function read(string $receiptId, string $actor, bool $live = true): array {
        if (!PolicyEventsMapper::validId($receiptId)) {
            throw new ReceiptNotFound();
        }
        try {
            $event = $this->events->find($receiptId);
            $payload = $event === null || $event['kind'] !== PolicyEventsMapper::KIND_RECEIPT ? null
                : PolicyEventPayload::validate(PolicyEventsMapper::KIND_RECEIPT, $event['payload']);
        } catch (\UnexpectedValueException $e) {
            throw new PolicyUnavailable('Invalid stored receipt', previous: $e);
        } catch (DatabaseException $e) {
            throw new PolicyUnavailable(previous: $e);
        }
        if ($payload === null || $payload['actor'] !== $actor) {
            throw new ReceiptNotFound();
        }
        if ($live && $this->time->getTime() >= $event['createdAt'] + PolicyLimits::RECEIPT_TTL) {
            throw new ReceiptExpired();
        }
        return ['revision' => $payload['revision'], 'changes' => $payload['changes']];
    }
}
