<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Migration\Version000800Date20261002000000;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCA\Mcp\Tools\Files\Batch;
use OCA\Mcp\Tools\Files\BatchStore;
use PHPUnit\Framework\TestCase;

/**
 * The production BatchStore against a SQLite table created by the app's own migration.
 *
 * The tool tests use {@see InMemoryBatchStore}, which only proves the double. These run the SQL the server runs: the
 * owner filter that keeps a batch of somebody else out of the undo plan, the purge on insert, the `undone_at IS NULL`
 * that lets a batch be undone once, and the narrowing a stopped undo needs to be retried.
 */
final class BatchStoreTest extends TestCase {
    private const NOW = 1790000000;
    private SqliteDatabase $db;
    private BatchStore $store;

    protected function setUp(): void {
        $this->db = new SqliteDatabase($this, [new Version000800Date20261002000000()]);
        $this->store = new BatchStore($this->db->connection());
    }

    /** @param list<array{from:string, to:string, toId:int}> $moves */
    private function batch(string $userId, array $moves = [], int $createdAt = self::NOW, array $dirs = []): Batch {
        return new Batch(null, $userId, $createdAt, $moves ?: [['from' => '/a.md', 'to' => '/B/a.md', 'toId' => 7]], $dirs, null);
    }

    public function testAStoredBatchComesBackWithEverythingTheUndoNeeds(): void {
        $moves = [['from' => '/a.md', 'to' => '/B/a.md', 'toId' => 7], ['from' => '/c.md', 'to' => '/B/c.md', 'toId' => 9]];
        $id = $this->store->insert($this->batch('alice', $moves, dirs: ['/B']));
        $found = $this->store->find($id, 'alice');
        $this->assertSame($id, $found->id);
        $this->assertSame('alice', $found->userId);
        $this->assertSame(self::NOW, $found->createdAt);
        $this->assertSame($moves, $found->moves);
        $this->assertSame(['/B'], $found->dirs);
        $this->assertNull($found->undoneAt);
    }

    /** The owner decides: the undo plan of a batch is the list of its paths, so another account must not read it. */
    public function testABatchOfSomebodyElseIsNotFound(): void {
        $id = $this->store->insert($this->batch('alice'));
        $this->assertNull($this->store->find($id, 'bob'));
        $this->assertNull($this->store->find($id + 1, 'alice'), 'um id que não existe responde igual');
    }

    public function testABatchIsMarkedUndoneOnlyOnceAndOnlyByItsOwner(): void {
        $id = $this->store->insert($this->batch('alice'));
        $this->assertFalse($this->store->markUndone($id, 'bob', self::NOW + 5), 'o lote não é de bob');
        $this->assertNull($this->store->find($id, 'alice')->undoneAt);
        $this->assertTrue($this->store->markUndone($id, 'alice', self::NOW + 10));
        $this->assertFalse($this->store->markUndone($id, 'alice', self::NOW + 20), 'a segunda marcação não acontece');
        $this->assertSame(self::NOW + 10, $this->store->find($id, 'alice')->undoneAt, 'a primeira data fica');
    }

    /** A stopped undo keeps only what did not go back, so the retry does not read the items already home as conflicts. */
    public function testKeepRemainingNarrowsTheMovesOfTheOwnersOpenBatch(): void {
        $moves = [['from' => '/a.md', 'to' => '/B/a.md', 'toId' => 7], ['from' => '/c.md', 'to' => '/B/c.md', 'toId' => 9]];
        $id = $this->store->insert($this->batch('alice', $moves, dirs: ['/B']));
        $this->store->keepRemaining($id, 'alice', [$moves[0]]);
        $kept = $this->store->find($id, 'alice');
        $this->assertSame([$moves[0]], $kept->moves);
        $this->assertSame(['/B'], $kept->dirs, 'as pastas criadas continuam registradas para o desfazer remover');
        $this->assertNull($kept->undoneAt);
    }

    public function testKeepRemainingNeverTouchesABatchOfSomebodyElseOrOneAlreadyUndone(): void {
        $moves = [['from' => '/a.md', 'to' => '/B/a.md', 'toId' => 7], ['from' => '/c.md', 'to' => '/B/c.md', 'toId' => 9]];
        $alice = $this->store->insert($this->batch('alice', $moves));
        $this->store->keepRemaining($alice, 'bob', []);
        $this->assertSame($moves, $this->store->find($alice, 'alice')->moves, 'bob não reescreve o lote de alice');
        $this->store->markUndone($alice, 'alice', self::NOW);
        $this->store->keepRemaining($alice, 'alice', []);
        $this->assertSame($moves, $this->store->find($alice, 'alice')->moves, 'um lote desfeito fica como terminou');
    }

    /** The purge drops the rows past their lifetime and nothing younger, whoever owns them. */
    public function testAnInsertDropsOnlyTheBatchesPastTheirLifetime(): void {
        $old = $this->store->insert($this->batch('bob', createdAt: self::NOW - BatchStore::LIFETIME_SECONDS - 1));
        $edge = $this->store->insert($this->batch('bob', createdAt: self::NOW - BatchStore::LIFETIME_SECONDS));
        $this->store->insert($this->batch('alice'));
        $this->assertNull($this->store->find($old, 'bob'), 'vencido sai');
        $this->assertNotNull($this->store->find($edge, 'bob'), 'no limite exato ainda fica');
        $this->assertCount(2, $this->db->rows(BatchStore::TABLE));
    }

    public function testDeleteForUserRemovesOnlyThatUsersBatches(): void {
        $this->store->insert($this->batch('alice'));
        $bob = $this->store->insert($this->batch('bob'));
        $this->store->deleteForUser('alice');
        $this->assertSame(['bob'], array_column($this->db->rows(BatchStore::TABLE), 'user_id'));
        $this->assertNotNull($this->store->find($bob, 'bob'));
    }
}
