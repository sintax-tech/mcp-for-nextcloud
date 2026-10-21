<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Db;

use OCA\Mcp\Access\Policy\DecimalCounter;
use OCA\Mcp\Access\Policy\Transactions\PolicyConflict;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** The server singleton connection is shared with official config and OAuth storage. */
class PolicyStateMapper {
    public const TABLE = 'mcp_policy_state';

    public function __construct(private IDBConnection $db) {}

    /**
     * Reads the single state row; counters stay canonical decimal strings, never platform integers.
     *
     * @return array{revision:string, lock_seq:string, auth_epoch:string}
     * @throws PolicyUnavailable when the row is missing or a counter is not canonical; runtime never re-seeds
     * @throws \OCP\DB\Exception when the query fails
     */
    public function state(): array {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('revision', 'lock_seq', 'auth_epoch')->from(self::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->executeQuery();
        try {
            $row = $result->fetch();
        } finally {
            $result->closeCursor();
        }
        if ($row === false) {
            throw new PolicyUnavailable('Missing policy state');
        }
        $state = [];
        foreach (['revision', 'lock_seq', 'auth_epoch'] as $key) {
            try {
                $state[$key] = DecimalCounter::validate((string)$row[$key], DecimalCounter::MAX);
            } catch (\InvalidArgumentException $e) {
                throw new PolicyUnavailable('Corrupt policy counter', previous: $e);
            }
        }
        return $state;
    }

    /**
     * Acquires a write lock before any local read, including on SQLite. Call only inside the writer's transaction.
     *
     * @throws PolicyUnavailable when the row is missing or the mutex counter is exhausted
     * @throws \OCP\DB\Exception when the update fails, including deadlock and lock-wait timeouts
     */
    public function acquire(): void {
        $qb = $this->db->getQueryBuilder();
        $count = $qb->update(self::TABLE)->set('lock_seq', $qb->createFunction('lock_seq + 1'))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lt('lock_seq', $qb->createNamedParameter(DecimalCounter::MAX)))
            ->executeStatement();
        if ($count !== 1) {
            throw new PolicyUnavailable('Missing or exhausted policy mutex');
        }
    }

    /**
     * Advances the revision by one, only from the revision the caller read under the mutex.
     *
     * @param string $expected canonical decimal revision read under the mutex
     * @throws \InvalidArgumentException when $expected is not a canonical counter below the maximum
     * @throws PolicyConflict when the stored revision is no longer $expected
     * @throws \OCP\DB\Exception when the update fails
     */
    public function compareAndSet(string $expected): void {
        DecimalCounter::validate($expected);
        $qb = $this->db->getQueryBuilder();
        $count = $qb->update(self::TABLE)->set('revision', $qb->createFunction('revision + 1'))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($expected)))
            ->executeStatement();
        if ($count !== 1) {
            throw new PolicyConflict();
        }
    }

    /**
     * Invalidates every OAuth credential at once (the service was switched off): auth_epoch moves by one, and only
     * credentials carrying the new epoch are valid afterwards. Call only under the writer's mutex.
     *
     * @param string $expected canonical auth_epoch read under the mutex
     * @return string the new auth_epoch
     * @throws \LogicException outside the policy writer's transaction
     * @throws PolicyUnavailable when the epoch is exhausted or moved outside the mutex
     * @throws \OCP\DB\Exception when the update fails
     */
    public function advanceAuthEpoch(string $expected): string {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('The authorization epoch changes only inside the policy writer transaction');
        }
        try {
            $next = DecimalCounter::increment($expected);
        } catch (\InvalidArgumentException $e) {
            throw new PolicyUnavailable('Authorization epoch exhausted', previous: $e);
        }
        $qb = $this->db->getQueryBuilder();
        $count = $qb->update(self::TABLE)->set('auth_epoch', $qb->createFunction('auth_epoch + 1'))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('auth_epoch', $qb->createNamedParameter($expected)))
            ->executeStatement();
        if ($count !== 1) {
            throw new PolicyUnavailable('Authorization epoch changed outside the policy mutex');
        }
        return $next;
    }
}
