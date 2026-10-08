<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Checkout;

use OCA\Mcp\Checkout\CheckoutToken;
use OCA\Mcp\Checkout\CheckoutTokenStore;
use OCA\Mcp\Tests\Unit\FakeDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Runs the production store against {@see FakeDatabase}. Single use is a conditional UPDATE whose
 * affected-row count decides the winner, so these tests execute that statement instead of the double's
 * array code: the double is what the controller tests lean on, and this is what proves it tells the truth.
 */
final class CheckoutTokenStoreTest extends TestCase {
    private const TABLE = 'mcp_checkout_tokens';

    private FakeDatabase $db;
    private CheckoutTokenStore $store;

    protected function setUp(): void {
        $this->db = new FakeDatabase($this, [self::TABLE => []], [self::TABLE], [self::TABLE => ['used_at' => null]]);
        $this->store = new CheckoutTokenStore($this->db->connection());
    }

    /** @return array<string, mixed> the columns insert() receives */
    private function row(string $hash, string $uid = 'alice', int $expires = 1000, string $kind = CheckoutToken::KIND_UPLOAD): array {
        return ['token_hash' => $hash, 'kind' => $kind, 'user_id' => $uid, 'file_id' => 42, 'path' => '/Documentos/ata.md',
            'etag' => 'etag-1', 'scope' => 'personal', 'shared_confirmed' => 1, 'created_at' => 100, 'expires_at' => $expires];
    }

    private function hashes(): array {
        return array_values(array_column($this->db->tables[self::TABLE], 'token_hash'));
    }

    public function testAStoredTokenIsFoundByItsHashWithEveryColumn(): void {
        $this->store->insert($this->row('hash-a'), 100);
        $this->store->insert($this->row('hash-b', 'bob', 2000, CheckoutToken::KIND_DOWNLOAD), 100);

        $token = $this->store->find('hash-b');

        $this->assertNotNull($token);
        $this->assertSame([CheckoutToken::KIND_DOWNLOAD, 'bob', 42, '/Documentos/ata.md', 'etag-1', 'personal', true, 2000, false],
            [$token->kind, $token->userId, $token->fileId, $token->path, $token->etag, $token->scope, $token->sharedConfirmed, $token->expiresAt, $token->used]);
        $this->assertNotSame($this->store->find('hash-a')->id, $token->id, 'every row has its own id');
        $this->assertNull($this->store->find('unknown'));
    }

    public function testFindReturnsExpiredAndSpentRowsSoTheControllerCanTellThemApart(): void {
        $this->store->insert($this->row('old', expires: 150), 100);
        $this->store->consume($this->store->find('old'), 120);

        $token = $this->store->find('old');

        $this->assertNotNull($token);
        $this->assertTrue($token->used);
        $this->assertSame(150, $token->expiresAt);
    }

    public function testATokenIsSpentOnceAndTheSecondRequestLoses(): void {
        $this->store->insert($this->row('hash-a'), 100);
        $token = $this->store->find('hash-a');

        $this->assertTrue($this->store->consume($token, 200));
        $this->assertFalse($this->store->consume($token, 201), 'the same row cannot be spent again');
        $this->assertTrue($this->store->find('hash-a')->used);
        $this->assertSame(200, $this->db->tables[self::TABLE][0]['used_at'], 'the first spend is the one recorded');
    }

    /** Two requests that both read the row as unused: only the UPDATE that finds `used_at` still null wins. */
    public function testTwoRequestsHoldingTheSameUnusedRowCannotBothSpendIt(): void {
        $this->store->insert($this->row('hash-a'), 100);
        $first = $this->store->find('hash-a');
        $second = $this->store->find('hash-a');

        $this->assertFalse($first->used || $second->used);
        $this->assertTrue($this->store->consume($first, 200));
        $this->assertFalse($this->store->consume($second, 200));
    }

    public function testAnExpiredTokenCannotBeSpentAndTheBoundaryIsExclusive(): void {
        $this->store->insert($this->row('hash-a', expires: 300), 100);
        $token = $this->store->find('hash-a');

        $this->assertFalse($this->store->consume($token, 300), 'expires_at == now is already expired');
        $this->assertFalse($this->store->consume($token, 301));
        $this->assertNull($this->db->tables[self::TABLE][0]['used_at'], 'a refused spend marks nothing');
        $this->assertTrue($this->store->consume($token, 299));
    }

    public function testSpendingOneTokenLeavesTheOthersUnspent(): void {
        $this->store->insert($this->row('hash-a'), 100);
        $this->store->insert($this->row('hash-b'), 100);

        $this->assertTrue($this->store->consume($this->store->find('hash-a'), 200));

        $this->assertFalse($this->store->find('hash-b')->used);
    }

    public function testInsertingPurgesTheExpiredTokensAndOnlyThose(): void {
        $this->store->insert($this->row('past', expires: 99), 50);
        $this->store->insert($this->row('edge', expires: 100), 50);
        $this->store->insert($this->row('future', expires: 101), 50);

        $this->store->insert($this->row('new', expires: 700), 100);

        $this->assertSame(['edge', 'future', 'new'], $this->hashes());
    }

    public function testDisconnectingAUserDeletesTheirLinksAndNobodyElses(): void {
        $this->store->insert($this->row('a1'), 100);
        $this->store->insert($this->row('a2', kind: CheckoutToken::KIND_DOWNLOAD), 100);
        $this->store->insert($this->row('b1', 'bob'), 100);

        $this->store->deleteForUser('alice');

        $this->assertSame(['b1'], $this->hashes());
        $this->assertNull($this->store->find('a1'));
    }

    public function testNoPlainTokenColumnExists(): void {
        $this->store->insert($this->row('hash-a'), 100);

        $this->assertSame(['id', 'token_hash', 'kind', 'user_id', 'file_id', 'path', 'etag', 'scope', 'shared_confirmed', 'created_at', 'expires_at', 'used_at'],
            array_keys($this->db->tables[self::TABLE][0]));
    }
}
