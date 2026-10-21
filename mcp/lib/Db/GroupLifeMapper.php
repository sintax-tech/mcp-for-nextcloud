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
use OCP\IDBConnection;
use UnexpectedValueException;

/**
 * Identity lifecycle of a GID trusted with policy (contracts §3.3.5): only an active row lets the group's grants
 * count. gen changes on every transition, so a delayed cleanup conditioned on the gen it read never removes a policy
 * saved later; policy_rev is the revision of the commit that saved the group's policy. mcp_group_grants is untouched.
 *
 * Composition mapper: writes run only inside the policy writer's transaction, under its global mutex, with one
 * exception: the two pre-event transitions of a deletion's Before, markSuspended() and markPendingDelete(), which never
 * take the mutex (see there).
 */
class GroupLifeMapper {
    public const TABLE = 'mcp_group_life';
    public const ACTIVE = 'active';
    public const PENDING_DELETE = 'pending_delete';
    public const NEEDS_CONFIRM = 'needs_confirm';
    public const STATES = [self::ACTIVE, self::PENDING_DELETE, self::NEEDS_CONFIRM];
    /** Leaves room below the parameter limits of the supported database engines. */
    private const CHUNK_SIZE = 200;

    public function __construct(private IDBConnection $db) {}

    /**
     * @param string $gid Nextcloud group id
     * @return array{groupId:string, state:string, gen:string, policyRev:string}|null null when the group has no lifecycle
     * @throws InvalidArgumentException for an invalid group id
     * @throws UnexpectedValueException for a digest collision or an invalid stored row
     * @throws \OCP\DB\Exception when the query fails
     */
    public function get(string $gid): ?array {
        return $this->forGroups([$gid])[0] ?? null;
    }

    /**
     * Lifecycles of the requested groups that have one, in input order without duplicates.
     *
     * @param list<string> $groupIds Nextcloud group ids
     * @return list<array{groupId:string, state:string, gen:string, policyRev:string}>
     * @throws InvalidArgumentException for an invalid list or id
     * @throws UnexpectedValueException for a digest collision or an invalid stored row; the whole read fails
     * @throws \OCP\DB\Exception when the query fails
     */
    public function forGroups(array $groupIds): array {
        if (!array_is_list($groupIds)) {
            throw new InvalidArgumentException('Group ids must be a list');
        }
        $wanted = [];
        foreach ($groupIds as $gid) {
            if (!is_string($gid)) {
                throw new InvalidArgumentException('Invalid group id');
            }
            $wanted[IdentityKey::group($gid)] = $gid;
        }
        $found = [];
        foreach (array_chunk($wanted, self::CHUNK_SIZE, true) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $keys = array_map(fn (string $key) => $qb->createNamedParameter($key), array_keys($chunk));
            $result = $qb->select('group_key', 'group_id', 'state', 'gen', 'policy_rev')->from(self::TABLE)
                ->where($qb->expr()->in('group_key', $keys))->executeQuery();
            try {
                while (($row = $result->fetch()) !== false) {
                    $key = $row['group_key'] ?? null;
                    if (!is_string($key) || !isset($chunk[$key]) || ($row['group_id'] ?? null) !== $chunk[$key]
                        || !in_array($row['state'] ?? null, self::STATES, true) || !self::validGen($row['gen'] ?? null)) {
                        throw new UnexpectedValueException('Invalid stored group lifecycle');
                    }
                    try {
                        $rev = DecimalCounter::validate((string)$row['policy_rev'], DecimalCounter::MAX);
                    } catch (InvalidArgumentException $e) {
                        throw new UnexpectedValueException('Invalid stored group lifecycle', 0, $e);
                    }
                    $found[$key] = ['groupId' => $chunk[$key], 'state' => $row['state'], 'gen' => $row['gen'], 'policyRev' => $rev];
                }
            } finally {
                $result->closeCursor();
            }
        }
        return array_values(array_intersect_key(array_replace($wanted, $found), $found));
    }

    /**
     * Creates or replaces the lifecycle of a group, as a save of its policy does.
     *
     * @param string $gid Nextcloud group id
     * @param string $state one of STATES
     * @param string $gen 32 lowercase hex characters, new for this transition
     * @param string $policyRev canonical revision of the commit that saves the group's policy
     * @throws LogicException outside the policy writer's transaction
     * @throws InvalidArgumentException for an invalid id, state, gen or revision
     * @throws UnexpectedValueException for a digest collision
     * @throws \OCP\DB\Exception when a statement fails
     */
    public function put(string $gid, string $state, string $gen, string $policyRev): void {
        $this->requireTransaction();
        self::validate($state, $gen);
        DecimalCounter::validate($policyRev, DecimalCounter::MAX);
        $qb = $this->db->getQueryBuilder();
        if ($this->get($gid) === null) {
            $qb->insert(self::TABLE)
                ->setValue('group_key', $qb->createNamedParameter(IdentityKey::group($gid)))
                ->setValue('group_id', $qb->createNamedParameter($gid))
                ->setValue('state', $qb->createNamedParameter($state))
                ->setValue('gen', $qb->createNamedParameter($gen))
                ->setValue('policy_rev', $qb->createNamedParameter($policyRev))
                ->executeStatement();
            return;
        }
        $qb->update(self::TABLE)
            ->set('state', $qb->createNamedParameter($state))
            ->set('gen', $qb->createNamedParameter($gen))
            ->set('policy_rev', $qb->createNamedParameter($policyRev))
            ->where($qb->expr()->eq('group_key', $qb->createNamedParameter(IdentityKey::group($gid))))
            ->executeStatement();
    }

    /**
     * Replaces an existing lifecycle, as a save of the group's policy does, only if it still carries $expectedGen: a
     * pre-event transition (markPendingDelete(), which never takes the mutex) committed after the caller read the row
     * is never overwritten. The caller replays its attempt when this returns false.
     *
     * @param string $gid Nextcloud group id
     * @param string $expectedGen gen read under the mutex
     * @param string $state one of STATES
     * @param string $gen new gen, different from $expectedGen
     * @param string $policyRev canonical revision of the commit that saves the group's policy
     * @return bool whether this call replaced it
     * @throws LogicException outside the policy writer's transaction
     * @throws InvalidArgumentException for an invalid id, state, gen or revision
     * @throws UnexpectedValueException for a digest collision
     * @throws \OCP\DB\Exception when a statement fails
     */
    public function replace(string $gid, string $expectedGen, string $state, string $gen, string $policyRev): bool {
        $this->requireTransaction();
        self::validate($state, $gen);
        DecimalCounter::validate($policyRev, DecimalCounter::MAX);
        if (!self::validGen($expectedGen) || $expectedGen === $gen) {
            throw new InvalidArgumentException('A replacement needs a new gen');
        }
        if (($this->get($gid)['gen'] ?? null) !== $expectedGen) {
            return false;
        }
        $qb = $this->db->getQueryBuilder();
        return $qb->update(self::TABLE)
            ->set('state', $qb->createNamedParameter($state))
            ->set('gen', $qb->createNamedParameter($gen))
            ->set('policy_rev', $qb->createNamedParameter($policyRev))
            ->where($qb->expr()->eq('group_key', $qb->createNamedParameter(IdentityKey::group($gid))))
            ->andWhere($qb->expr()->eq('gen', $qb->createNamedParameter($expectedGen)))
            ->executeStatement() === 1;
    }

    /**
     * Moves the lifecycle to $state with a new gen, only if it still carries $expectedGen.
     *
     * @param string $gid Nextcloud group id
     * @param string $expectedGen gen read before the transition
     * @param string $state one of STATES
     * @param string $gen new gen, different from $expectedGen
     * @return bool whether this call moved it
     * @throws LogicException outside the policy writer's transaction
     * @throws InvalidArgumentException for an invalid id, state or gen
     * @throws UnexpectedValueException for a digest collision
     * @throws \OCP\DB\Exception when a statement fails
     */
    public function transition(string $gid, string $expectedGen, string $state, string $gen): bool {
        $this->requireTransaction();
        self::validate($state, $gen);
        if (!self::validGen($expectedGen) || $expectedGen === $gen) {
            throw new InvalidArgumentException('A transition needs a new gen');
        }
        if (($this->get($gid)['gen'] ?? null) !== $expectedGen) {
            return false;
        }
        $qb = $this->db->getQueryBuilder();
        return $qb->update(self::TABLE)
            ->set('state', $qb->createNamedParameter($state))
            ->set('gen', $qb->createNamedParameter($gen))
            ->where($qb->expr()->eq('group_key', $qb->createNamedParameter(IdentityKey::group($gid))))
            ->andWhere($qb->expr()->eq('gen', $qb->createNamedParameter($expectedGen)))
            ->executeStatement() === 1;
    }

    /**
     * BeforeGroupDeletedEvent (contracts §3.3.5), its first step: any state -> needs_confirm with a new gen, only if
     * the row still carries $expectedGen, as one conditional statement outside any transaction (RF1 of the re-reading
     * of F1, 15/10/2026). The Before then captures the holders and records their causes, and only when that is
     * complete moves the row on to pending_delete (markPendingDelete(), conditioned on the gen written here).
     *
     * The capture thereby has a durable, fail-closed state: until it is complete the lifecycle is needs_confirm, which
     * grants nothing (only active does), deletes nothing and is never purged by the job (it purges only pending_delete).
     * A capture that fails, or a process that dies before the last step, leaves the policy suspended until an
     * administrator decides, never purged without the holders' causes. Like markPendingDelete() it takes no mutex and
     * does not move the revision, with the same reasoning for readers and writers (see there); every command whose
     * decision relied on the group's lifecycle compares or renews its gen under its mutex and sees it leave active.
     *
     * @param string $gid Nextcloud group id
     * @param string $expectedGen gen read before the transition
     * @param string $gen new random gen, different from $expectedGen
     * @return bool whether this call moved it; false when the row is gone or carries another gen
     * @throws LogicException inside a transaction: a pre-event handler runs no SQL there (contracts §3.3.6)
     * @throws InvalidArgumentException for an invalid id or gen
     * @throws UnexpectedValueException for a digest collision or an invalid stored row
     * @throws \OCP\DB\Exception when a statement fails
     */
    public function markSuspended(string $gid, string $expectedGen, string $gen): bool {
        return $this->preEvent($gid, $expectedGen, self::NEEDS_CONFIRM, $gen);
    }

    /**
     * BeforeGroupDeletedEvent (contracts §3.3.5), its last step: any state -> pending_delete with a new gen (a new
     * deletion renews the gen of an earlier one that failed), only if the row still carries $expectedGen, as one
     * conditional statement outside any transaction. The Before writes it only once the holders' causes are recorded
     * (markSuspended() comes first, see there), so the job never purges a lifecycle whose capture is missing.
     *
     * With markSuspended(), the only lifecycle transitions that run without the policy mutex and do not move the
     * revision: a
     * pre-event handler never takes the mutex (contracts §3.3), and the native deletion is not confirmed yet. It is
     * safe because pending_delete grants nothing (only active does) and deletes nothing: every final transition
     * (purge, needs_confirm, the job's decision) still goes through the writer, under the mutex, with revision + 1.
     * Readers that snapshot by revision are affected as follows. A resolution that read the row as active just before
     * this statement used the state the group had while it still existed, as it would have without this signal; the
     * next one reads pending_delete and the group no longer grants. A writer command that compares only the revision
     * (and its other snapshots) cannot see this transition. Every command whose decision relied on a group's lifecycle
     * must compare the gen it read in its external phase again under the mutex: the group save and confirm of ticket 08
     * (and its H0/H1 holders rule), and every issuance a group admitted: consent, exchange and refresh find the groups
     * that admitted the user still active and renew their gens (\OCA\Mcp\Access\Grants\Groups\LifecycleHolds::hold()), as does a reset that
     * leaves a user eligible through a group. That renewal is what orders them with this transition: a Before that read
     * the gen before such a commit loses its expected gen here and judges the holders again (F1 of the second reading
     * of 1.1.0). A page built on expectedRevision (the matrix) may still show such a group as active.
     *
     * The row is read first and its original id compared byte for byte, so a digest collision fails closed before the
     * update; the update is conditioned on the gen verified by that read.
     *
     * @param string $gid Nextcloud group id
     * @param string $expectedGen gen read before the transition
     * @param string $gen new random gen, different from $expectedGen
     * @return bool whether this call moved it; false when the row is gone or carries another gen
     * @throws LogicException inside a transaction: a pre-event handler runs no SQL there (contracts §3.3.6)
     * @throws InvalidArgumentException for an invalid id or gen
     * @throws UnexpectedValueException for a digest collision or an invalid stored row
     * @throws \OCP\DB\Exception when a statement fails
     */
    public function markPendingDelete(string $gid, string $expectedGen, string $gen): bool {
        return $this->preEvent($gid, $expectedGen, self::PENDING_DELETE, $gen);
    }

    /** The conditional statement of markSuspended() and markPendingDelete(), outside any transaction. */
    private function preEvent(string $gid, string $expectedGen, string $state, string $gen): bool {
        if ($this->db->inTransaction()) {
            throw new LogicException('The pre-event lifecycle transition never runs inside a transaction');
        }
        self::validate($state, $gen);
        if (!self::validGen($expectedGen) || $expectedGen === $gen) {
            throw new InvalidArgumentException('A transition needs a new gen');
        }
        $row = $this->get($gid);
        if ($row === null || $row['gen'] !== $expectedGen) {
            return false;
        }
        $qb = $this->db->getQueryBuilder();
        return $qb->update(self::TABLE)
            ->set('state', $qb->createNamedParameter($state))
            ->set('gen', $qb->createNamedParameter($gen))
            ->where($qb->expr()->eq('group_key', $qb->createNamedParameter(IdentityKey::group($gid))))
            ->andWhere($qb->expr()->eq('gen', $qb->createNamedParameter($expectedGen)))
            ->executeStatement() === 1;
    }

    /**
     * Group ids waiting for their deletion to be confirmed, for the reconciler job: at most $limit, by group_key, after
     * the cursor $after. The ids are returned as stored and not verified here: the job reads each one through get(),
     * which compares it with its digest, one row at a time, so an invalid or colliding row is skipped without stopping
     * the others.
     *
     * @param int $limit most rows returned
     * @param string $after group_key the previous page ended with; '' for the first page
     * @return list<array{groupKey:string, groupId:string}>
     * @throws \OCP\DB\Exception when the query fails
     */
    public function pendingDeletes(int $limit, string $after = ''): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('group_key', 'group_id')->from(self::TABLE)
            ->where($qb->expr()->eq('state', $qb->createNamedParameter(self::PENDING_DELETE)));
        if ($after !== '') {
            $qb->andWhere($qb->expr()->gt('group_key', $qb->createNamedParameter($after)));
        }
        $result = $qb->orderBy('group_key')->setMaxResults(max(1, $limit))->executeQuery();
        $rows = [];
        try {
            while (($row = $result->fetch()) !== false) {
                $rows[] = ['groupKey' => (string)($row['group_key'] ?? ''), 'groupId' => (string)($row['group_id'] ?? '')];
            }
        } finally {
            $result->closeCursor();
        }
        return $rows;
    }

    /**
     * Removes the lifecycle only if it still carries $expectedGen. The caller removes the group's grant rows in the
     * same transaction.
     *
     * @param string $gid Nextcloud group id
     * @param string $expectedGen gen read before the removal
     * @return bool whether this call removed it
     * @throws LogicException outside the policy writer's transaction
     * @throws InvalidArgumentException for an invalid id or gen
     * @throws UnexpectedValueException for a digest collision
     * @throws \OCP\DB\Exception when the statement fails
     */
    public function delete(string $gid, string $expectedGen): bool {
        $this->requireTransaction();
        if (!self::validGen($expectedGen)) {
            throw new InvalidArgumentException('Invalid gen');
        }
        if (($this->get($gid)['gen'] ?? null) !== $expectedGen) {
            return false;
        }
        $qb = $this->db->getQueryBuilder();
        return $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('group_key', $qb->createNamedParameter(IdentityKey::group($gid))))
            ->andWhere($qb->expr()->eq('gen', $qb->createNamedParameter($expectedGen)))
            ->executeStatement() === 1;
    }

    private static function validate(string $state, string $gen): void {
        if (!in_array($state, self::STATES, true) || !self::validGen($gen)) {
            throw new InvalidArgumentException('Invalid group lifecycle');
        }
    }

    private static function validGen(mixed $gen): bool {
        return is_string($gen) && preg_match('/^[0-9a-f]{32}$/D', $gen) === 1;
    }

    private function requireTransaction(): void {
        if (!$this->db->inTransaction()) {
            throw new LogicException('Group lifecycles change only inside the policy writer transaction');
        }
    }
}
