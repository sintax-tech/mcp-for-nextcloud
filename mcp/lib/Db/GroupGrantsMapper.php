<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Db;

use InvalidArgumentException;
use OCP\IDBConnection;
use UnexpectedValueException;

/**
 * Group-grant storage, deliberately not a QBMapper: its inherited write methods must not be exposed. A row means
 * Allow; no row means no grant. This store does not resolve user access or group membership. Its only write, the
 * purge of known group rows and replacement of its known set by a save (ticket 08), run inside the policy writer's
 * transaction, under its mutex (group lifecycle, contracts §3.3.5).
 */
final class GroupGrantsMapper {
    public function __construct(private IDBConnection $db) {}

    /**
     * Returns each distinct requested id in input order, including groups without grants. Identifiers stay strings,
     * so numeric-looking ids cannot become PHP array keys. Module and operation lists are sorted bytewise.
     *
     * @param list<string> $groupIds opaque, non-empty UTF-8 group ids, with no NUL bytes
     * @return list<array{groupId:string, grants:array<string, list<string>>, unknownGrants?:list<array>, grantSnapshot?:string}>
     * @throws InvalidArgumentException for an invalid input list or id
     * @throws UnexpectedValueException for a digest collision, mismatched identity or malformed stored grant;
     *     the whole read fails instead of returning a partially trusted result
     */
    public function forGroups(array $groupIds): array {
        return (new GroupGrantRows($this->db))->read($groupIds);
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
     * Removes known grant rows of one group. Unknown rows await explicit administrative cleanup. The reader
     * compares identities as forGroups() does, so a digest collision fails closed before the delete.
     *
     * @param string $gid Nextcloud group id
     * @return int rows removed
     * @throws \LogicException outside the policy writer's transaction
     * @throws InvalidArgumentException for an invalid id
     * @throws UnexpectedValueException for a digest collision or a malformed stored grant; nothing is deleted
     * @throws \OCP\DB\Exception when a statement fails
     */
    public function deleteGroup(string $gid): int {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Group grants change only inside the policy writer transaction');
        }
        return (new GroupGrantRows($this->db))->removeKnown($gid);
    }

    /**
     * Replaces known grant rows of one group with $grants, preserving unknown rows for explicit cleanup. A save
     * in pending_delete or needs_confirm substitutes the known set. An empty set removes only known rows. The
     * old rows go through deleteGroup(), so a digest collision fails closed before anything is written.
     *
     * @param string $gid Nextcloud group id
     * @param array<string, list<string>> $grants canonical set (PolicyEventPayload::groupGrants())
     * @throws \LogicException outside the policy writer's transaction
     * @throws InvalidArgumentException for an invalid id or set
     * @throws UnexpectedValueException for a digest collision or a malformed stored grant; nothing is written
     * @throws \OCP\DB\Exception when a statement fails
     */
    public function replaceGroup(string $gid, array $grants): void {
        if (\OCA\Mcp\Service\Policy\PolicyEventPayload::groupGrants($grants) !== $grants) {
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
