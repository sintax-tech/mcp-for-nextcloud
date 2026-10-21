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
 * Per-user resource scope (contracts B.1–B.3): a mode row restricts one module for one user, and only the resources in
 * its item rows stay reachable, possibly none. No mode row means unrestricted; item rows without a mode have no effect.
 * There is no boolean mode column. Resource ids are the canonical decimal ids of B.2 (Files folder id, calendar id).
 *
 * Every read compares the stored user id and resource ids byte for byte against their digests, so a collision or an
 * inconsistent row fails the whole read instead of narrowing or widening it. Composition mapper: writes run only
 * inside the policy writer's transaction, under its global mutex, and verify the rows they replace before any write.
 */
class ResourceScopeMapper {
    public const MODES_TABLE = 'mcp_scope_modes';
    public const ITEMS_TABLE = 'mcp_scope_items';
    /** Modules a scope can restrict (decision D1). */
    public const MODULES = ['files', 'calendar', 'tasks'];
    /** Most resources one user may keep in the scope of one module. */
    public const MAX_ITEMS = 200;

    public function __construct(private IDBConnection $db) {}

    /**
     * The restricted modules of one user, ordered by module name bytes.
     *
     * @param string $uid Nextcloud user id
     * @return array<string, array{policyRev:string, items:list<string>}> by module; an unrestricted module is absent,
     *         and a module restricted to nothing has an empty item list
     * @throws InvalidArgumentException for an invalid user id
     * @throws UnexpectedValueException for a digest collision or an invalid stored row; the whole read fails
     * @throws Exception when a query fails
     */
    public function forUser(string $uid): array {
        $scopes = $this->scopeRows($uid, IdentityKey::user($uid), null);
        ksort($scopes, SORT_STRING);
        return $scopes;
    }

    /**
     * @param string $uid Nextcloud user id
     * @param string $module one of MODULES
     * @return array{policyRev:string, items:list<string>}|null null when the module is unrestricted for the user
     * @throws InvalidArgumentException for an invalid user id or module
     * @throws UnexpectedValueException for a digest collision or an invalid stored row
     * @throws Exception when a query fails
     */
    public function forModule(string $uid, string $module): ?array {
        self::checkModule($module);
        return $this->scopeRows($uid, IdentityKey::user($uid), $module)[$module] ?? null;
    }

    /**
     * How many resources each restricted module of each user keeps, for the matrix: no ids and no names. One
     * statement, so a page never pairs a mode with items of another commit.
     *
     * @param list<string> $uids Nextcloud user ids, at most \OCA\Mcp\Access\Policy\PolicyLimits::MAX_UIDS
     * @return array<string, array<string, int>> count by module by uid; an unrestricted module is absent
     * @throws InvalidArgumentException for an invalid user id
     * @throws UnexpectedValueException for a user identity collision or an invalid stored row
     * @throws Exception when the query fails
     */
    public function counts(array $uids): array {
        $byKey = [];
        foreach (array_values(array_unique($uids)) as $uid) {
            $byKey[IdentityKey::user($uid)] = $uid;
        }
        $counts = array_fill_keys(array_values($byKey), []);
        if ($byKey === []) {
            return $counts;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('m.user_key', 'm.module', 'm.user_id')
            ->selectAlias($qb->func()->count('i.resource_key'), 'items')
            ->from(self::MODES_TABLE, 'm')
            ->leftJoin('m', self::ITEMS_TABLE, 'i', $qb->expr()->andX(
                $qb->expr()->eq('i.user_key', 'm.user_key'),
                $qb->expr()->eq('i.module', 'm.module'),
            ))
            ->where($qb->expr()->in('m.user_key', array_map(fn (string $key) => $qb->createNamedParameter($key), array_keys($byKey))))
            ->groupBy('m.user_key', 'm.module', 'm.user_id');
        $result = $qb->executeQuery();
        try {
            while (($row = $result->fetch()) !== false) {
                $uid = $byKey[(string)($row['user_key'] ?? '')] ?? null;
                $module = $row['module'] ?? null;
                if ($uid === null || ($row['user_id'] ?? null) !== $uid) {
                    throw new UnexpectedValueException('User identity collision');
                }
                if (!is_string($module) || !in_array($module, self::MODULES, true) || isset($counts[$uid][$module])) {
                    throw new UnexpectedValueException('Invalid stored resource scope');
                }
                $count = (int)$row['items'];
                if ($count < 0 || $count > self::MAX_ITEMS) {
                    throw new UnexpectedValueException('Invalid stored resource scope');
                }
                $counts[$uid][$module] = $count;
            }
        } finally {
            $result->closeCursor();
        }
        foreach ($counts as &$modules) {
            ksort($modules, SORT_STRING);
        }
        unset($modules);
        return $counts;
    }

    /**
     * Replaces the scope of one module for one user: null makes it unrestricted, a list restricts it to exactly those
     * resources (an empty list restricts it to nothing). The current mode and items of (user, module) are read and
     * verified first; a row of another identity under the same digest fails closed with no write.
     *
     * @param string $uid Nextcloud user id
     * @param string $module one of MODULES
     * @param list<string>|null $resourceIds unique canonical decimal ids, at most MAX_ITEMS; null for unrestricted
     * @param string $policyRev canonical revision of the commit that saves this scope
     * @throws LogicException outside the policy writer's transaction
     * @throws InvalidArgumentException for an invalid user id, module, revision or resource list
     * @throws UnexpectedValueException for a digest collision or an inconsistent stored row, before any write
     * @throws Exception when a statement fails
     */
    public function replace(string $uid, string $module, ?array $resourceIds, string $policyRev): void {
        $this->requireTransaction();
        self::checkModule($module);
        $userKey = IdentityKey::user($uid);
        DecimalCounter::validate($policyRev, DecimalCounter::MAX);
        $items = $resourceIds === null ? null : self::checkedItems($resourceIds);

        // Verifies the stored mode and items of this (user, module) before deleting them.
        $this->modeRows($uid, $userKey, $module);
        $this->itemRows($userKey, $module);

        $this->deleteModule($userKey, $module, null);
        if ($items === null) {
            return;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->insert(self::MODES_TABLE)
            ->setValue('user_key', $qb->createNamedParameter($userKey))
            ->setValue('module', $qb->createNamedParameter($module))
            ->setValue('user_id', $qb->createNamedParameter($uid))
            ->setValue('policy_rev', $qb->createNamedParameter($policyRev))
            ->executeStatement();
        foreach ($items as $id) {
            $qb = $this->db->getQueryBuilder();
            $qb->insert(self::ITEMS_TABLE)
                ->setValue('user_key', $qb->createNamedParameter($userKey))
                ->setValue('module', $qb->createNamedParameter($module))
                ->setValue('resource_key', $qb->createNamedParameter(IdentityKey::resource($module, $id)))
                ->setValue('resource_id', $qb->createNamedParameter($id))
                ->executeStatement();
        }
    }

    /**
     * Removes the scopes of a user that were saved at or before $maxPolicyRev, as the account cleanup does: a scope
     * saved later (the account recreated and configured again) is kept. Every mode of the user is verified first.
     *
     * @param string $uid Nextcloud user id
     * @param string $maxPolicyRev canonical revision read before the account deletion started
     * @return list<string> the modules whose scope this call removed, ordered by name bytes
     * @throws LogicException outside the policy writer's transaction
     * @throws InvalidArgumentException for an invalid user id or revision
     * @throws UnexpectedValueException for a digest collision or an inconsistent stored row, before any write
     * @throws Exception when a statement fails
     */
    public function removeUpTo(string $uid, string $maxPolicyRev): array {
        $this->requireTransaction();
        $userKey = IdentityKey::user($uid);
        DecimalCounter::validate($maxPolicyRev, DecimalCounter::MAX);
        $modes = $this->modeRows($uid, $userKey, null);
        $this->itemRows($userKey, null);
        $removed = [];
        foreach ($modes as $module => $policyRev) {
            if (DecimalCounter::compare($policyRev, $maxPolicyRev) <= 0 && $this->deleteModule($userKey, $module, $policyRev)) {
                $removed[] = $module;
            }
        }
        sort($removed, SORT_STRING);
        return $removed;
    }

    /**
     * Users that have at least one mode row, for the job's diagnostic pass: at most $limit, by user_key, after the cursor
     * $after. The ids are returned as stored and not verified here: the job compares each with its digest, one row at a
     * time, so an invalid or colliding row is skipped without stopping the others.
     *
     * @param int $limit most users returned
     * @param string $after user_key the previous page ended with; '' for the first page
     * @return list<array{userKey:string, userId:string}>
     * @throws Exception when the query fails
     */
    public function usersWithModes(int $limit, string $after = ''): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_key', 'user_id')->from(self::MODES_TABLE)->groupBy('user_key', 'user_id');
        if ($after !== '') {
            $qb->where($qb->expr()->gt('user_key', $qb->createNamedParameter($after)));
        }
        $result = $qb->orderBy('user_key')->setMaxResults(max(1, $limit))->executeQuery();
        $rows = [];
        try {
            while (($row = $result->fetch()) !== false) {
                $rows[] = ['userKey' => (string)($row['user_key'] ?? ''), 'userId' => (string)($row['user_id'] ?? '')];
            }
        } finally {
            $result->closeCursor();
        }
        return $rows;
    }

    /**
     * How many users keep item rows of a module they have no mode row for (contracts B.3: such items have no effect, and
     * removeUpTo() leaves them). Only a count, for the job's diagnostic: the item table names no user.
     *
     * @return int distinct user keys with at least one orphan item row
     * @throws Exception when the query fails
     */
    public function orphanItemUsers(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->createFunction('COUNT(DISTINCT i.user_key)'), 'orphans')
            ->from(self::ITEMS_TABLE, 'i')
            ->leftJoin('i', self::MODES_TABLE, 'm', $qb->expr()->andX(
                $qb->expr()->eq('m.user_key', 'i.user_key'),
                $qb->expr()->eq('m.module', 'i.module'),
            ))
            ->where($qb->expr()->isNull('m.user_key'));
        $result = $qb->executeQuery();
        try {
            $row = $result->fetch();
            return $row === false ? 0 : (int)($row['orphans'] ?? 0);
        } finally {
            $result->closeCursor();
        }
    }

    /**
     * Deletes the mode and the items of (user_key, module), the mode only while it carries $policyRev when given.
     *
     * @return bool whether a mode row was deleted
     */
    private function deleteModule(string $userKey, string $module, ?string $policyRev): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::MODES_TABLE)
            ->where($qb->expr()->eq('user_key', $qb->createNamedParameter($userKey)))
            ->andWhere($qb->expr()->eq('module', $qb->createNamedParameter($module)));
        if ($policyRev !== null) {
            $qb->andWhere($qb->expr()->eq('policy_rev', $qb->createNamedParameter($policyRev)));
        }
        $deleted = $qb->executeStatement() === 1;
        if ($policyRev !== null && !$deleted) {
            return false; // the mode changed since it was read: its items stay with it
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::ITEMS_TABLE)
            ->where($qb->expr()->eq('user_key', $qb->createNamedParameter($userKey)))
            ->andWhere($qb->expr()->eq('module', $qb->createNamedParameter($module)))
            ->executeStatement();
        return $deleted;
    }

    /**
     * The modes of one user, optionally of one module, with their items, in one statement: two reads outside a
     * transaction could pair a mode of one commit with the items of another. The join is by user only, so every item
     * row of the user is verified like itemRows() does, even one of another module or without a mode, and a single
     * invalid row fails the whole read; items without a mode still have no effect.
     *
     * @return array<string, array{policyRev:string, items:list<string>}> by module
     * @throws UnexpectedValueException for another identity under the same digest, or an invalid row
     */
    private function scopeRows(string $uid, string $userKey, ?string $module): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('m.module', 'm.user_id', 'm.policy_rev')
            ->selectAlias('i.module', 'item_module')
            ->selectAlias('i.resource_key', 'resource_key')
            ->selectAlias('i.resource_id', 'resource_id')
            ->from(self::MODES_TABLE, 'm')
            ->leftJoin('m', self::ITEMS_TABLE, 'i', $qb->expr()->eq('i.user_key', 'm.user_key'))
            ->where($qb->expr()->eq('m.user_key', $qb->createNamedParameter($userKey)));
        if ($module !== null) {
            $qb->andWhere($qb->expr()->eq('m.module', $qb->createNamedParameter($module)));
        }
        $result = $qb->executeQuery();
        $scopes = [];
        $items = [];
        try {
            while (($row = $result->fetch()) !== false) {
                if (($row['user_id'] ?? null) !== $uid) {
                    throw new UnexpectedValueException('User identity collision');
                }
                $stored = $row['module'] ?? null;
                if (!is_string($stored) || !in_array($stored, self::MODULES, true) || ($module !== null && $stored !== $module)) {
                    throw new UnexpectedValueException('Invalid stored resource scope');
                }
                try {
                    $policyRev = DecimalCounter::validate((string)($row['policy_rev'] ?? ''), DecimalCounter::MAX);
                } catch (InvalidArgumentException $e) {
                    throw new UnexpectedValueException('Invalid stored resource scope', 0, $e);
                }
                // One mode row per (user, module): every joined row of a module repeats the same revision.
                if (isset($scopes[$stored]) && $scopes[$stored]['policyRev'] !== $policyRev) {
                    throw new UnexpectedValueException('Invalid stored resource scope');
                }
                $scopes[$stored] ??= ['policyRev' => $policyRev, 'items' => []];
                $itemModule = $row['item_module'] ?? null;
                $key = $row['resource_key'] ?? null;
                $id = $row['resource_id'] ?? null;
                if ($itemModule === null && $key === null && $id === null) {
                    continue; // the user has no item row at all
                }
                if (!is_string($itemModule) || !in_array($itemModule, self::MODULES, true)
                    || !is_string($id) || !self::canonical($id) || $key !== IdentityKey::resource($itemModule, $id)) {
                    throw new UnexpectedValueException('Invalid stored resource scope');
                }
                // The join repeats each item once per mode row of the user; the primary key keeps them distinct.
                $items[$itemModule][$id] = true;
            }
        } finally {
            $result->closeCursor();
        }
        foreach ($scopes as $stored => &$scope) {
            $ids = array_map('strval', array_keys($items[$stored] ?? []));
            usort($ids, DecimalCounter::compare(...));
            $scope['items'] = self::checkedCount($ids);
        }
        unset($scope);
        return $scopes;
    }

    /**
     * Mode rows of one user, optionally of one module, verified in PHP: the stored user id is $uid byte for byte,
     * the module is known and the revision canonical.
     *
     * @return array<string, string> policy_rev by module
     * @throws UnexpectedValueException for another identity under the same digest, or an invalid row
     */
    private function modeRows(string $uid, string $userKey, ?string $module): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('module', 'user_id', 'policy_rev')->from(self::MODES_TABLE)
            ->where($qb->expr()->eq('user_key', $qb->createNamedParameter($userKey)));
        if ($module !== null) {
            $qb->andWhere($qb->expr()->eq('module', $qb->createNamedParameter($module)));
        }
        $result = $qb->executeQuery();
        $modes = [];
        try {
            while (($row = $result->fetch()) !== false) {
                if (($row['user_id'] ?? null) !== $uid) {
                    throw new UnexpectedValueException('User identity collision');
                }
                $stored = $row['module'] ?? null;
                if (!is_string($stored) || !in_array($stored, self::MODULES, true) || isset($modes[$stored])
                    || ($module !== null && $stored !== $module)) {
                    throw new UnexpectedValueException('Invalid stored resource scope');
                }
                try {
                    $modes[$stored] = DecimalCounter::validate((string)($row['policy_rev'] ?? ''), DecimalCounter::MAX);
                } catch (InvalidArgumentException $e) {
                    throw new UnexpectedValueException('Invalid stored resource scope', 0, $e);
                }
            }
        } finally {
            $result->closeCursor();
        }
        return $modes;
    }

    /**
     * Item rows of one user key, optionally of one module, verified in PHP: each resource_key is the digest of its
     * module and original id, and the id is canonical.
     *
     * @return array<string, list<string>> resource ids by module, in ascending numeric order
     * @throws UnexpectedValueException for a digest collision or an invalid row
     */
    private function itemRows(string $userKey, ?string $module): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('module', 'resource_key', 'resource_id')->from(self::ITEMS_TABLE)
            ->where($qb->expr()->eq('user_key', $qb->createNamedParameter($userKey)));
        if ($module !== null) {
            $qb->andWhere($qb->expr()->eq('module', $qb->createNamedParameter($module)));
        }
        $result = $qb->executeQuery();
        $items = [];
        try {
            while (($row = $result->fetch()) !== false) {
                $stored = $row['module'] ?? null;
                $id = $row['resource_id'] ?? null;
                if (!is_string($stored) || !in_array($stored, self::MODULES, true) || ($module !== null && $stored !== $module)
                    || !is_string($id) || !self::canonical($id)
                    || ($row['resource_key'] ?? null) !== IdentityKey::resource($stored, $id)) {
                    throw new UnexpectedValueException('Invalid stored resource scope');
                }
                $items[$stored][] = $id;
            }
        } finally {
            $result->closeCursor();
        }
        foreach ($items as &$ids) {
            usort($ids, DecimalCounter::compare(...));
        }
        unset($ids);
        return $items;
    }

    /**
     * @param list<string> $items stored ids of one restricted module
     * @return list<string> the same ids
     * @throws UnexpectedValueException above MAX_ITEMS, which no write can produce
     */
    private static function checkedCount(array $items): array {
        if (count($items) > self::MAX_ITEMS) {
            throw new UnexpectedValueException('Invalid stored resource scope');
        }
        return $items;
    }

    /**
     * @param mixed $resourceIds candidate resource list
     * @return list<string> the ids, in ascending numeric order
     * @throws InvalidArgumentException for a non-list, a non-canonical id, a duplicate or more than MAX_ITEMS ids
     */
    private static function checkedItems(mixed $resourceIds): array {
        if (!is_array($resourceIds) || !array_is_list($resourceIds) || count($resourceIds) > self::MAX_ITEMS) {
            throw new InvalidArgumentException('Invalid resource scope');
        }
        $unique = [];
        foreach ($resourceIds as $id) {
            if (!is_string($id) || !self::canonical($id) || isset($unique[$id])) {
                throw new InvalidArgumentException('Invalid resource scope');
            }
            $unique[$id] = true;
        }
        $ids = array_map('strval', array_keys($unique));
        usort($ids, DecimalCounter::compare(...));
        return $ids;
    }

    /** Whether an id is a positive canonical decimal that fits a signed BIGINT. */
    private static function canonical(string $id): bool {
        try {
            return DecimalCounter::validate($id, DecimalCounter::MAX) !== '0';
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** @throws InvalidArgumentException for a module outside MODULES */
    private static function checkModule(string $module): void {
        if (!in_array($module, self::MODULES, true)) {
            throw new InvalidArgumentException('Invalid scope module');
        }
    }

    private function requireTransaction(): void {
        if (!$this->db->inTransaction()) {
            throw new LogicException('Resource scopes change only inside the policy writer transaction');
        }
    }
}
