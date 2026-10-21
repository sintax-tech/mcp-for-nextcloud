<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Db;

use InvalidArgumentException;
use LogicException;
use OCA\Mcp\Access\Policy\DecimalCounter;
use OCP\DB\Exception;
use OCP\IDBConnection;
use UnexpectedValueException;

/**
 * Causes to re-evaluate (contracts §3.3.1): one row per (user, cause), where the cause is an account deletion or the
 * removal from one group. A cause neither blocks nor authorizes; it is consumed with its exact gen, so an After event,
 * retry or job of a cause already consumed, or replaced by a newer native operation, finds nothing to do.
 */
class RecheckMapper {
    public const TABLE = 'mcp_auth_recheck';
    public const REASON_ACCOUNT = 'account_delete';
    public const REASON_GROUP = 'group_removal';

    public function __construct(private IDBConnection $db) {}

    /**
     * The cause set R0/R1 of one user, ordered by cause_key bytes.
     *
     * @param string $uid Nextcloud user id
     * @return list<array{causeKey:string, reason:string, groupId:?string, gen:string, createdAt:string}>
     * @throws InvalidArgumentException for an invalid user id
     * @throws UnexpectedValueException for a digest collision or an inconsistent stored cause; the whole read fails
     * @throws Exception when the query fails
     */
    public function causes(string $uid): array {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('cause_key', 'user_id', 'reason', 'group_id', 'gen', 'created_at')->from(self::TABLE)
            ->where($qb->expr()->eq('user_key', $qb->createNamedParameter(IdentityKey::user($uid))))
            ->executeQuery();
        $causes = [];
        try {
            while (($row = $result->fetch()) !== false) {
                if (($row['user_id'] ?? null) !== $uid) {
                    throw new UnexpectedValueException('User identity collision');
                }
                $reason = $row['reason'] ?? null;
                $groupId = $row['group_id'] ?? null;
                $key = $row['cause_key'] ?? null;
                $expected = match ($reason) {
                    self::REASON_ACCOUNT => $groupId === null ? IdentityKey::accountCause() : null,
                    self::REASON_GROUP => is_string($groupId) && $groupId !== '' ? IdentityKey::group($groupId) : null,
                    default => null,
                };
                if ($expected === null || $key !== $expected || !self::validGen($row['gen'] ?? null)) {
                    throw new UnexpectedValueException('Invalid stored recheck cause');
                }
                try {
                    $createdAt = DecimalCounter::validate((string)$row['created_at'], DecimalCounter::MAX);
                } catch (InvalidArgumentException $e) {
                    throw new UnexpectedValueException('Invalid stored recheck cause', 0, $e);
                }
                $causes[] = ['causeKey' => $key, 'reason' => $reason, 'groupId' => $groupId, 'gen' => $row['gen'], 'createdAt' => $createdAt];
            }
        } finally {
            $result->closeCursor();
        }
        usort($causes, static fn (array $a, array $b): int => strcmp($a['causeKey'], $b['causeKey']));
        return $causes;
    }

    /**
     * Users with at least one pending cause, for the reconciler: a bounded page in user_key order, after a cursor. The
     * reconciler moves the cursor past the users it just processed, so users whose causes stay pending (unknown
     * eligibility) cannot hold every page: the next run continues after them and wraps around at the end.
     *
     * @param int $limit most users returned, at least 1
     * @param string $after user_key of the last user of the previous page; '' starts at the beginning
     * @return list<string> user ids as stored; causes() verifies each identity before anything is consumed
     * @throws Exception when the query fails
     */
    public function pendingUsers(int $limit, string $after = ''): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct(['user_key', 'user_id'])->from(self::TABLE);
        if ($after !== '') {
            $qb->where($qb->expr()->gt('user_key', $qb->createNamedParameter($after)));
        }
        $result = $qb->orderBy('user_key')->setMaxResults(max(1, $limit))->executeQuery();
        $users = [];
        try {
            while (($row = $result->fetch()) !== false) {
                if (is_string($row['user_id'] ?? null)) {
                    $users[] = $row['user_id'];
                }
            }
        } finally {
            $result->closeCursor();
        }
        return $users;
    }

    /**
     * Records a cause, replacing the gen of the same (user, cause): a new native operation always gets a new gen.
     *
     * Every write is preceded by a read of the exact row, whose original ids are compared byte for byte in PHP (never
     * by SQL equality, which a case-insensitive collation could satisfy), and the UPDATE is conditioned on the gen of
     * the row so verified. A row of another identity under the same digest therefore fails closed with no write, also
     * when it is inserted concurrently between the read and the write.
     *
     * Inside the policy writer's transaction the absent-row insert runs under the mutex, and a unique violation
     * propagates. Outside any transaction (a pre-event handler, which never takes the mutex) a racing insert of the
     * same cause is resolved by reading that row again and verifying it before updating; this recovery never runs
     * inside a transaction, where PostgreSQL would abort it.
     *
     * @param string $uid Nextcloud user id
     * @param string $reason REASON_ACCOUNT or REASON_GROUP
     * @param string|null $gid the group for REASON_GROUP, null for REASON_ACCOUNT
     * @param string $gen 32 lowercase hex characters (128 random bits), new for each native operation
     * @param int $createdAt Unix time of the native operation
     * @return string the cause_key
     * @throws InvalidArgumentException for an invalid id, reason, group or gen
     * @throws UnexpectedValueException when the stored row belongs to another identity (digest collision) or is
     *     inconsistent, before any write; or when the row keeps changing concurrently
     * @throws Exception when a statement fails
     */
    public function record(string $uid, string $reason, ?string $gid, string $gen, int $createdAt): string {
        $causeKey = match (true) {
            $reason === self::REASON_ACCOUNT && $gid === null => IdentityKey::accountCause(),
            $reason === self::REASON_GROUP && $gid !== null => IdentityKey::group($gid),
            default => throw new InvalidArgumentException('Invalid recheck cause'),
        };
        if (!self::validGen($gen) || $createdAt < 0) {
            throw new InvalidArgumentException('Invalid recheck cause');
        }
        $userKey = IdentityKey::user($uid);
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $stored = $this->stored($uid, $userKey, $causeKey, $gid);
            if ($stored !== null) {
                // The duplicate Before events of one native operation reuse its gen: recording it again changes
                // nothing, and an update that matches but changes no value would report zero rows on some engines.
                if ($stored === $gen || $this->update($userKey, $causeKey, $stored, $gen, $createdAt)) {
                    return $causeKey;
                }
                // Replaced since the read: verify the new row before writing anything.
                continue;
            }
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->insert(self::TABLE)
                    ->setValue('user_key', $qb->createNamedParameter($userKey))
                    ->setValue('cause_key', $qb->createNamedParameter($causeKey))
                    ->setValue('user_id', $qb->createNamedParameter($uid))
                    ->setValue('reason', $qb->createNamedParameter($reason))
                    ->setValue('group_id', $qb->createNamedParameter($gid))
                    ->setValue('gen', $qb->createNamedParameter($gen))
                    ->setValue('created_at', $qb->createNamedParameter((string)$createdAt))
                    ->executeStatement();
                return $causeKey;
            } catch (Exception $e) {
                if ($this->db->inTransaction() || $e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                    throw $e;
                }
                // Another session inserted this key first: the next read verifies its identity before any update.
            }
        }
        throw new UnexpectedValueException('Recheck cause changed concurrently');
    }

    /**
     * Consumes one cause only if it still carries the gen that was classified, and only if the stored row is this
     * user's: the original ids are compared byte for byte before the delete.
     *
     * @param string $uid Nextcloud user id
     * @param string $causeKey cause_key from causes()
     * @param string $gen gen read in R0
     * @return bool whether this call removed it
     * @throws LogicException outside the policy writer's transaction
     * @throws InvalidArgumentException for an invalid user id
     * @throws UnexpectedValueException when the stored row belongs to another identity or is inconsistent, without
     *     deleting it
     * @throws Exception when a statement fails
     */
    public function consume(string $uid, string $causeKey, string $gen): bool {
        if (!$this->db->inTransaction()) {
            throw new LogicException('Recheck causes are consumed only inside the policy writer transaction');
        }
        $userKey = IdentityKey::user($uid);
        // Under the mutex the row can only change its gen (a pre-event record), never its identity: an absent key is
        // the only one another session can insert, and the delete is conditioned on the gen verified here.
        if ($this->stored($uid, $userKey, $causeKey, null) !== $gen) {
            return false;
        }
        $qb = $this->db->getQueryBuilder();
        return $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('user_key', $qb->createNamedParameter($userKey)))
            ->andWhere($qb->expr()->eq('cause_key', $qb->createNamedParameter($causeKey)))
            ->andWhere($qb->expr()->eq('gen', $qb->createNamedParameter($gen)))
            ->executeStatement() === 1;
    }

    /**
     * Reads one cause row and verifies it in PHP: the stored user id is $uid byte for byte, and the stored reason and
     * group id produce $causeKey (and equal $gid byte for byte when given).
     *
     * @return string|null the stored gen, or null without a row
     * @throws UnexpectedValueException for another identity under the same digest, or an inconsistent row
     */
    private function stored(string $uid, string $userKey, string $causeKey, ?string $gid): ?string {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('user_id', 'reason', 'group_id', 'gen')->from(self::TABLE)
            ->where($qb->expr()->eq('user_key', $qb->createNamedParameter($userKey)))
            ->andWhere($qb->expr()->eq('cause_key', $qb->createNamedParameter($causeKey)))
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
        $groupId = $row['group_id'] ?? null;
        $consistent = match ($row['reason'] ?? null) {
            self::REASON_ACCOUNT => $groupId === null && $causeKey === IdentityKey::accountCause(),
            self::REASON_GROUP => is_string($groupId) && $groupId !== '' && IdentityKey::group($groupId) === $causeKey
                && ($gid === null || $groupId === $gid),
            default => false,
        };
        if (!$consistent || !self::validGen($row['gen'] ?? null)) {
            throw new UnexpectedValueException('Invalid stored recheck cause');
        }
        return $row['gen'];
    }

    /** Changes the gen only of the row whose identity was verified with $expectedGen. */
    private function update(string $userKey, string $causeKey, string $expectedGen, string $gen, int $createdAt): bool {
        $qb = $this->db->getQueryBuilder();
        return $qb->update(self::TABLE)
            ->set('gen', $qb->createNamedParameter($gen))
            ->set('created_at', $qb->createNamedParameter((string)$createdAt))
            ->where($qb->expr()->eq('user_key', $qb->createNamedParameter($userKey)))
            ->andWhere($qb->expr()->eq('cause_key', $qb->createNamedParameter($causeKey)))
            ->andWhere($qb->expr()->eq('gen', $qb->createNamedParameter($expectedGen)))
            ->executeStatement() === 1;
    }

    private static function validGen(mixed $gen): bool {
        return is_string($gen) && preg_match('/^[0-9a-f]{32}$/D', $gen) === 1;
    }
}
