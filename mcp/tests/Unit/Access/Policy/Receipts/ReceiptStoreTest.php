<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Access\Policy\Receipts;

use OCA\Mcp\Access\Policy\Events\PolicyEventPayload;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\Receipts\ReceiptStore;
use OCA\Mcp\Access\Policy\Receipts\ReceiptExpired;
use OCA\Mcp\Access\Policy\Receipts\ReceiptNotFound;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

final class ReceiptStoreTest extends TestCase {
    private function store(?array $event, int $now = 1000): ReceiptStore {
        $events = $this->createMock(PolicyEventsMapper::class);
        $events->method('find')->willReturn($event);
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn($now);
        return new ReceiptStore($events, $time);
    }

    private function receipt(string $actor = 'root'): array {
        return ['kind' => 'receipt', 'createdAt' => 1000, 'payload' => PolicyEventPayload::receipt($actor, 'grants.update', '0', '1',
            [['uid' => 'alice', 'key' => 'eligible', 'before' => null, 'after' => '1']])];
    }

    public function testStoredAbsenceIsPreservedAndOnlyItsActorMayReadIt(): void {
        $store = $this->store($this->receipt());
        $this->assertNull($store->read(str_repeat('a', 32), 'root')['changes'][0]['before']);
        $this->expectException(ReceiptNotFound::class);
        $store->read(str_repeat('a', 32), 'another-admin');
    }

    public function testReceiptExpiresAtItsTtlButAnExpiredReceiptCanBeDescribed(): void {
        $store = $this->store($this->receipt(), 1600);
        $this->assertSame('1', $store->read(str_repeat('a', 32), 'root', false)['revision']);
        $this->expectException(ReceiptExpired::class);
        $store->read(str_repeat('a', 32), 'root');
    }

    public function testCorruptPayloadIsUnavailableInsteadOfPartiallyRestored(): void {
        $event = $this->receipt();
        $event['payload']['changes'][] = ['uid' => 'alice'];
        $this->expectException(PolicyUnavailable::class);
        $this->store($event)->read(str_repeat('a', 32), 'root');
    }

    public function testAuditCannotBeUsedAsReceipt(): void {
        $event = $this->receipt();
        $event['kind'] = 'audit';
        $this->expectException(ReceiptNotFound::class);
        $this->store($event)->read(str_repeat('a', 32), 'root');
    }
}
