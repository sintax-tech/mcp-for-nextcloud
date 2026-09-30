<?php
declare(strict_types=1);

namespace OCA\Mcp\Checkout;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Persistence of the single-use checkout tokens (table mcp_checkout_tokens).
 *
 * Only keyed hashes are stored. Single use is enforced by a conditional UPDATE whose affected-row count
 * decides the outcome, so two concurrent requests carrying the same token can never both win.
 *
 * Not final: the unit tests replace it with an in-memory double that keeps the same single-use semantics,
 * the way the OAuth store is doubled.
 */
class CheckoutTokenStore {
    /** Table holding the tokens. */
    public const TABLE = 'mcp_checkout_tokens';

    public function __construct(private IDBConnection $db) {}

    /**
     * Stores a token, first purging every row that expired.
     *
     * @param array{token_hash:string, kind:string, user_id:string, file_id:int, path:string, etag:string, scope:string, shared_confirmed:int, created_at:int, expires_at:int} $row token without its id
     * @param int $now current time
     */
    public function insert(array $row, int $now): void {
        $purge = $this->db->getQueryBuilder();
        $purge->delete(self::TABLE)
            ->where($purge->expr()->lt('expires_at', $purge->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE);
        foreach ($row as $column => $value) {
            $qb->setValue($column, $qb->createNamedParameter($value, is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR));
        }
        $qb->executeStatement();
    }

    /**
     * @param string $tokenHash keyed hash of the presented token
     * @return CheckoutToken|null the row, expired or already spent ones included
     */
    public function find(string $tokenHash): ?CheckoutToken {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('*')->from(self::TABLE)
            ->where($qb->expr()->eq('token_hash', $qb->createNamedParameter($tokenHash)))
            ->setMaxResults(1)->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return is_array($row) ? CheckoutToken::fromRow($row) : null;
    }

    /**
     * Spends the token exactly once.
     *
     * @param CheckoutToken $token row previously returned by find()
     * @param int $now current time
     * @return bool true only for the single request that marked it used
     */
    public function consume(CheckoutToken $token, int $now): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('used_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($token->id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNull('used_at'))
            ->andWhere($qb->expr()->gt('expires_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
        return $qb->executeStatement() === 1;
    }

    /**
     * Deletes every pending token of a user, so a personal disconnect invalidates the links it issued.
     *
     * @param string $uid Nextcloud user id
     */
    public function deleteForUser(string $uid): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))->executeStatement();
    }
}
