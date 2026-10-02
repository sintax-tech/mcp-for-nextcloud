<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Tools\Files\Batch;
use OCA\Mcp\Tools\Files\BatchStore;
use OCP\IDBConnection;

/**
 * In-memory BatchStore for the tool tests, keeping the two rules the real store exists to enforce: the
 * owner decides whether a batch is found, and a batch can only be marked undone once.
 *
 * It only overrides what {@see BatchStore} has ({@see \OCA\Mcp\Tests\Unit\StoreDoublesContractTest}); a test
 * looks inside it through {@see self::$rows}, and the SQL itself is covered by {@see BatchStoreTest}.
 */
final class InMemoryBatchStore extends BatchStore {
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    private int $nextId = 1;

    public function __construct(IDBConnection $db) {
        // The connection is never used: this double answers from memory, the way a test needs to see it.
        parent::__construct($db);
    }

    public function insert(Batch $batch): int {
        // Like the real store, an insert first drops the batches whose lifetime has ended.
        $cutoff = $batch->createdAt - self::LIFETIME_SECONDS;
        $this->rows = array_filter($this->rows, static fn (array $row): bool => $row['created_at'] >= $cutoff);
        $id = $this->nextId++;
        $this->rows[$id] = $batch->toRow() + ['id' => $id];
        return $id;
    }

    public function find(int $id, string $userId): ?Batch {
        $row = $this->rows[$id] ?? null;
        return $row === null || $row['user_id'] !== $userId ? null : Batch::fromRow($row);
    }

    public function keepRemaining(int $id, string $userId, array $moves): void {
        if (isset($this->rows[$id]) && $this->rows[$id]['user_id'] === $userId && $this->rows[$id]['undone_at'] === null) {
            $this->rows[$id]['moves_json'] = json_encode($moves, JSON_THROW_ON_ERROR);
        }
    }

    public function markUndone(int $id, string $userId, int $now): bool {
        $row = $this->rows[$id] ?? null;
        if ($row === null || $row['user_id'] !== $userId || $row['undone_at'] !== null) {
            return false;
        }
        $this->rows[$id]['undone_at'] = $now;
        return true;
    }

    public function deleteForUser(string $uid): void {
        $this->rows = array_filter($this->rows, fn (array $row) => $row['user_id'] !== $uid);
    }
}
