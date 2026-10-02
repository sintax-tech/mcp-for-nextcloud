<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Persistence of the executed batches (table mcp_file_batches).
 *
 * A batch outlives the request so the user can undo it later, which makes two things a promise rather than a
 * detail: the row is written with the batch it describes, and finding it requires the owner, so an id that
 * belongs to somebody else is answered exactly like one that never existed.
 *
 * Not final: the unit tests replace it with an in-memory double, the way the checkout store is doubled.
 */
class BatchStore {
    /** Table holding the batches. */
    public const TABLE = 'mcp_file_batches';
    /** How long a batch stays undoable. */
    public const LIFETIME_SECONDS = 2592000;

    public function __construct(private IDBConnection $db) {}

    /**
     * Stores a batch, first dropping the ones whose lifetime has ended. It is a row that goes, not a file
     * of the user: /MCP backups never gets a window like this one.
     *
     * @param Batch $batch the batch that ran
     * @return int the id of the stored row
     */
    public function insert(Batch $batch): int {
        $cutoff = $batch->createdAt - self::LIFETIME_SECONDS;
        $expired = $this->db->getQueryBuilder();
        $expired->delete(self::TABLE)
            ->where($expired->expr()->lt('created_at', $expired->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE);
        foreach ($batch->toRow() as $column => $value) {
            $qb->setValue($column, $qb->createNamedParameter($value, $value === null
                ? IQueryBuilder::PARAM_NULL
                : (is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR)));
        }
        $qb->executeStatement();
        return (int)$this->db->lastInsertId(self::TABLE);
    }

    /**
     * @param int $id batch id
     * @param string $userId the account asking; a batch of somebody else is answered as not found
     * @return Batch|null the batch
     */
    public function find(int $id, string $userId): ?Batch {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')->from(self::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->setMaxResults(1)->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return is_array($row) ? Batch::fromRow($row) : null;
    }

    /**
     * Marks a batch as undone, so a second undo says so instead of moving things twice.
     *
     * @param int $id batch id
     * @param string $userId the account that owns it
     * @param int $now current time
     * @return bool true only for the request that marked it
     */
    public function markUndone(int $id, string $userId, int $now): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('undone_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->isNull('undone_at'));
        return $qb->executeStatement() === 1;
    }

    /**
     * Narrows an open batch to the moves that did not go back, after an undo that stopped halfway.
     *
     * The items already home are no longer in their destination, and a retry that still listed them would read
     * that absence as a conflict and refuse the batch for good. A batch of somebody else, or one already undone,
     * is left exactly as it is.
     *
     * @param int $id batch id
     * @param string $userId the account that owns it
     * @param list<array{from:string, to:string, toId:int}> $moves the moves still to undo, in the order they ran
     */
    public function keepRemaining(int $id, string $userId, array $moves): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('moves_json', $qb->createNamedParameter(json_encode($moves, JSON_THROW_ON_ERROR)))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->isNull('undone_at'))
            ->executeStatement();
    }

    /**
     * Deletes every batch of a user, so a personal disconnect leaves nothing undoable behind.
     *
     * @param string $uid Nextcloud user id
     */
    public function deleteForUser(string $uid): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))->executeStatement();
    }
}
