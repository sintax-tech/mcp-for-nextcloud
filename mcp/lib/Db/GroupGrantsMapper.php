<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Db;

use OCA\Mcp\Access\Policy\Events\PolicyEventPayload;

use InvalidArgumentException;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCP\IDBConnection;
use UnexpectedValueException;

/**
 * Group-grant storage, deliberately not a QBMapper: its inherited write methods must not be exposed. A row means
 * Allow; no row means no grant. This store does not resolve user access or group membership. Its only write, the
 * purge of a group's rows and the replacement of its whole set by a save (ticket 08), run inside the policy writer's
 * transaction, under its mutex (group lifecycle, contracts §3.3.5).
 */
final class GroupGrantsMapper {
    /** Leaves room below the parameter limits of the supported database engines. */
    private const CHUNK_SIZE = 200;

    public function __construct(private IDBConnection $db) {}

    /**
     * Returns each distinct requested id in input order, including groups without grants. Identifiers stay strings,
     * so numeric-looking ids cannot become PHP array keys. Module and operation lists are sorted bytewise.
     *
     * @param list<string> $groupIds opaque, non-empty UTF-8 group ids, with no NUL bytes
     * @return list<array{groupId:string, grants:array<string, list<string>>}>
     * @throws InvalidArgumentException for an invalid input list or id
     * @throws UnexpectedValueException for a digest collision, mismatched identity or invalid stored grant;
     *     the whole read fails instead of returning a partially trusted result
     */
    public function forGroups(array $groupIds): array {
        if (!array_is_list($groupIds)) {
            throw new InvalidArgumentException('Group ids must be a list');
        }
        $groups = [];
        foreach ($groupIds as $gid) {
            if (!is_string($gid) || $gid === '' || str_contains($gid, "\0") || !mb_check_encoding($gid, 'UTF-8')) {
                throw new InvalidArgumentException('Invalid group id');
            }
            $key = hash('sha256', "group\0" . $gid);
            if (isset($groups[$key]) && $groups[$key]['groupId'] !== $gid) {
                throw new UnexpectedValueException('Group identity collision');
            }
            $groups[$key] = ['groupId' => $gid, 'grants' => []];
        }
        foreach (array_chunk($groups, self::CHUNK_SIZE, true) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $keys = array_map(fn (string $key) => $qb->createNamedParameter($key), array_keys($chunk));
            $result = $qb->select('group_key', 'group_id', 'module', 'operation')->from('mcp_group_grants')
                ->where($qb->expr()->in('group_key', $keys))->executeQuery();
            try {
                while (($row = $result->fetch()) !== false) {
                    $key = $row['group_key'] ?? null;
                    $gid = $row['group_id'] ?? null;
                    $module = $row['module'] ?? null;
                    $operation = $row['operation'] ?? null;
                    if (!is_string($key) || !isset($chunk[$key]) || $gid !== $chunk[$key]['groupId']
                        || !is_string($module) || !is_string($operation)
                        || !(GrantPolicy::inCatalog($module, $operation) || ($module === '_system' && $operation === 'eligible'))
                        || in_array($operation, $groups[$key]['grants'][$module] ?? [], true)) {
                        throw new UnexpectedValueException('Invalid stored group grant');
                    }
                    $groups[$key]['grants'][$module][] = $operation;
                }
            } finally {
                $result->closeCursor();
            }
        }
        foreach ($groups as &$group) {
            ksort($group['grants'], SORT_STRING);
            foreach ($group['grants'] as &$operations) {
                sort($operations, SORT_STRING);
            }
            unset($operations);
        }
        unset($group);
        return array_values($groups);
    }

    /**
     * The groups holding one grant, for the enumeration of the users it reaches (the eligible filter and totals of
     * the admin matrix). Only the rows of this app; whether a group may grant is decided by its caller.
     *
     * @param string $module catalog module, or '_system' for eligibility
     * @param string $operation operation of that module
     * @return list<string> group ids, unique, sorted bytewise
     * @throws UnexpectedValueException for a row whose key is not the digest of its id
     * @throws \OCP\DB\Exception when the query fails
     */
    public function groupsGranting(string $module, string $operation): array {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('group_key', 'group_id')->from('mcp_group_grants')
            ->where($qb->expr()->eq('module', $qb->createNamedParameter($module)))
            ->andWhere($qb->expr()->eq('operation', $qb->createNamedParameter($operation)))
            ->executeQuery();
        $groups = [];
        try {
            while (($row = $result->fetch()) !== false) {
                $gid = $row['group_id'] ?? null;
                if (!is_string($gid) || ($row['group_key'] ?? null) !== hash('sha256', "group\0" . $gid)) {
                    throw new UnexpectedValueException('Invalid stored group grant');
                }
                $groups[$row['group_key']] = $gid;
            }
        } finally {
            $result->closeCursor();
        }
        $groups = array_values($groups);
        sort($groups, SORT_STRING);
        return $groups;
    }

    /**
     * Removes every grant row of one group, as the purge of its lifecycle does. The rows are read first through
     * forGroups(), which compares each stored id byte for byte, so a digest collision fails closed before the delete.
     *
     * @param string $gid Nextcloud group id
     * @return int rows removed
     * @throws \LogicException outside the policy writer's transaction
     * @throws InvalidArgumentException for an invalid id
     * @throws UnexpectedValueException for a digest collision or an invalid stored grant; nothing is deleted
     * @throws \OCP\DB\Exception when a statement fails
     */
    public function deleteGroup(string $gid): int {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Group grants change only inside the policy writer transaction');
        }
        $this->forGroups([$gid]);
        $qb = $this->db->getQueryBuilder();
        return $qb->delete('mcp_group_grants')
            ->where($qb->expr()->eq('group_key', $qb->createNamedParameter(hash('sha256', "group\0" . $gid))))
            ->executeStatement();
    }

    /**
     * Replaces every grant row of one group with $grants, as an administrator's save does (contracts §3.3.5: a save
     * in pending_delete or needs_confirm substitutes the whole set). An empty set leaves the group without rows. The
     * old rows go through deleteGroup(), so a digest collision fails closed before anything is written.
     *
     * @param string $gid Nextcloud group id
     * @param array<string, list<string>> $grants canonical set (PolicyEventPayload::groupGrants())
     * @throws \LogicException outside the policy writer's transaction
     * @throws InvalidArgumentException for an invalid id or set
     * @throws UnexpectedValueException for a digest collision or an invalid stored grant; nothing is written
     * @throws \OCP\DB\Exception when a statement fails
     */
    public function replaceGroup(string $gid, array $grants): void {
        if (\OCA\Mcp\Access\Policy\Events\PolicyEventPayload::groupGrants($grants) !== $grants) {
            throw new InvalidArgumentException('Group grants must be canonical');
        }
        $this->deleteGroup($gid);
        foreach ($grants as $module => $operations) {
            foreach ($operations as $operation) {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('mcp_group_grants')
                    ->setValue('group_key', $qb->createNamedParameter(hash('sha256', "group\0" . $gid)))
                    ->setValue('group_id', $qb->createNamedParameter($gid))
                    ->setValue('module', $qb->createNamedParameter((string)$module))
                    ->setValue('operation', $qb->createNamedParameter($operation))
                    ->executeStatement();
            }
        }
    }
}
