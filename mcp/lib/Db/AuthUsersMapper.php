<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Db;

use LogicException;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use UnexpectedValueException;

/**
 * Per-user OAuth generations (contracts §3.1): issue_seq is the last generation issued, and every generation up to
 * revoked_below is invalid. A user without a row is (1, 0), which is exactly what every credential of 1.0.8 carries.
 * Rows are tombstones: deleting or recreating the account never removes them.
 *
 * Composition mapper: writes run only inside the policy writer's transaction, under its global mutex, so the
 * read-then-insert of an absent row cannot race another app writer; a unique violation there is a broken invariant
 * and rolls the whole transaction back.
 */
class AuthUsersMapper {
    public const TABLE = 'mcp_auth_users';
    /** Generation of every credential that predates this table, and of a user who never consented since. */
    public const INITIAL_ISSUE = '1';
    public const INITIAL_REVOKED = '0';

    public function __construct(private IDBConnection $db) {}

    /**
     * @param string $uid Nextcloud user id
     * @return array{issue_seq:string, revoked_below:string} canonical decimal strings; (1, 0) without a row
     * @throws \InvalidArgumentException for an invalid user id
     * @throws UnexpectedValueException when the stored row belongs to a different id (digest collision)
     * @throws PolicyUnavailable when a stored counter is not canonical
     * @throws \OCP\DB\Exception when the query fails
     */
    public function generations(string $uid): array {
        return $this->row($uid) ?? ['issue_seq' => self::INITIAL_ISSUE, 'revoked_below' => self::INITIAL_REVOKED];
    }

    /**
     * Advances issue_seq by one for a new consent and returns the generation the new code carries.
     *
     * @param string $uid Nextcloud user id
     * @return string the new issue_seq
     * @throws LogicException outside the policy writer's transaction
     * @throws PolicyUnavailable when the counter is exhausted or not canonical
     * @throws UnexpectedValueException when the stored row belongs to a different id
     * @throws \OCP\DB\Exception when a statement fails
     */
    public function issue(string $uid): string {
        $this->requireTransaction();
        $current = $this->row($uid);
        if ($current === null) {
            $this->insert($uid, '2', self::INITIAL_REVOKED);
            return '2';
        }
        try {
            DecimalCounter::validate($current['issue_seq']);
        } catch (\InvalidArgumentException $e) {
            throw new PolicyUnavailable('Authorization generation exhausted', previous: $e);
        }
        $qb = $this->db->getQueryBuilder();
        $count = $qb->update(self::TABLE)->set('issue_seq', $qb->createFunction('issue_seq + 1'))
            ->where($qb->expr()->eq('user_key', $qb->createNamedParameter(IdentityKey::user($uid))))
            ->andWhere($qb->expr()->eq('issue_seq', $qb->createNamedParameter($current['issue_seq'])))
            ->executeStatement();
        if ($count !== 1) {
            throw new PolicyUnavailable('Authorization generation changed outside the policy mutex');
        }
        return $this->generations($uid)['issue_seq'];
    }

    /**
     * Revokes every generation up to $cut: revoked_below = max(revoked_below, $cut). Monotonic and idempotent; a
     * retry with the same cut changes nothing.
     *
     * @param string $uid Nextcloud user id
     * @param string $cut canonical generation read under the mutex, never above issue_seq
     * @return bool whether revoked_below moved
     * @throws LogicException outside the policy writer's transaction
     * @throws \InvalidArgumentException for a non-canonical cut, or one above the last issued generation
     * @throws UnexpectedValueException when the stored row belongs to a different id
     * @throws \OCP\DB\Exception when a statement fails
     */
    public function cut(string $uid, string $cut): bool {
        $this->requireTransaction();
        $current = $this->row($uid);
        $issued = $current['issue_seq'] ?? self::INITIAL_ISSUE;
        if (DecimalCounter::compare($cut, $issued) > 0) {
            throw new \InvalidArgumentException('A cut cannot exceed the last issued generation');
        }
        if ($current === null) {
            if (DecimalCounter::compare($cut, self::INITIAL_REVOKED) <= 0) {
                return false;
            }
            $this->insert($uid, self::INITIAL_ISSUE, $cut);
            return true;
        }
        if (DecimalCounter::compare($cut, $current['revoked_below']) <= 0) {
            return false;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)->set('revoked_below', $qb->createNamedParameter($cut))
            ->where($qb->expr()->eq('user_key', $qb->createNamedParameter(IdentityKey::user($uid))))
            ->executeStatement();
        return true;
    }

    /** @return array{issue_seq:string, revoked_below:string}|null */
    private function row(string $uid): ?array {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('user_id', 'issue_seq', 'revoked_below')->from(self::TABLE)
            ->where($qb->expr()->eq('user_key', $qb->createNamedParameter(IdentityKey::user($uid))))
            ->executeQuery();
        try {
            $row = $result->fetch();
        } finally {
            $result->closeCursor();
        }
        if ($row === false) {
            return null;
        }
        if (($row['user_id'] ?? null) !== $uid) {
            throw new UnexpectedValueException('User identity collision');
        }
        try {
            return [
                'issue_seq' => DecimalCounter::validate((string)$row['issue_seq'], DecimalCounter::MAX),
                'revoked_below' => DecimalCounter::validate((string)$row['revoked_below'], DecimalCounter::MAX),
            ];
        } catch (\InvalidArgumentException $e) {
            throw new PolicyUnavailable('Corrupt authorization generation', previous: $e);
        }
    }

    private function insert(string $uid, string $issue, string $revoked): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::TABLE)
            ->setValue('user_key', $qb->createNamedParameter(IdentityKey::user($uid)))
            ->setValue('user_id', $qb->createNamedParameter($uid))
            ->setValue('issue_seq', $qb->createNamedParameter($issue))
            ->setValue('revoked_below', $qb->createNamedParameter($revoked))
            ->executeStatement();
    }

    private function requireTransaction(): void {
        if (!$this->db->inTransaction()) {
            throw new LogicException('Authorization generations change only inside the policy writer transaction');
        }
    }
}
