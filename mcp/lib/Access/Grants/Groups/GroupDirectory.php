<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Grants\Groups;

use OCA\Mcp\Access\Grants\GrantPolicy;

use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Access\Grants\Groups\GroupSourceClassifier;
use OCA\Mcp\Access\Policy\Events\PolicyEventPayload;
use OCA\Mcp\Access\Policy\Resolution\PolicyResolver;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCP\IDBConnection;
use OCP\IGroupManager;
use Throwable;

/**
 * Read-only admin group directory. Public directory APIs run outside transactions and mutexes. Listing assembles
 * names before sorting/paging (50 rows); there is no 200-group cutoff. Classification costs one backend existence
 * call per backend per displayed group, shared by trusted/exists. Each page uses two app scans for policy-only IDs
 * and two batched SELECTs for grants/lifecycles (not per-row SQL). Detail enumerates a trusted group's members once,
 * then resolves grants in blocks of 200 (one official membership read per member, batched app SQL/raw overrides,
 * no scope or log reads), independently of the user matrix's page. This is linear in the full member set,
 * intentionally not a subtotal of the first page. Unknown counts remain null.
 */
class GroupDirectory {
    public function __construct(
        private IGroupManager $groups,
        private IDBConnection $db,
        private GroupGrantsMapper $grants,
        private GroupLifeMapper $life,
        private GroupSourceClassifier $classifier,
        private PolicyResolver $resolver,
        private PolicyStateMapper $state,
    ) {}

    /** Filters of the list: every group, the groups with a stored grant, and the groups that cannot grant. */
    public const FILTERS = ['all', 'granted', 'blocked'];

    /**
     * One page of 50 groups, after the search and the filter. "granted" keeps the groups with a stored grant, read from
     * the grants table this page already scans. "blocked" keeps the groups the source classifier says cannot grant
     * (trusted false); it classifies every group the search matches, once, and reuses that for the page. counts gives
     * the size of each filter within the search; the blocked one is null unless that filter is active, since only then
     * is it known without classifying the whole directory.
     *
     * @return array{groups: list<array>, page: int, pageSize: int, hasMore: bool, total: int, filter: string,
     *     counts: array{all: int, granted: int, blocked: int|null}}
     */
    public function page(string $search = '', int $page = 1, string $filter = 'all'): array {
        if ($page < 1 || $page > 100000 || mb_strlen($search) > 200 || !in_array($filter, self::FILTERS, true)) {
            throw new \InvalidArgumentException('Invalid request');
        }
        return $this->read(function () use ($search, $page, $filter): array {
            $all = [];
            $granted = [];
            // Query all directory names for stable global ordering; policy-only GIDs are merged below.
            foreach ($this->groups->search('') as $group) {
                $gid = $group->getGID();
                if (!PolicyEventPayload::validUid($gid)) {
                    // Keep the diagnostic row JSON-safe without inventing a writable replacement identity.
                    $all['invalid:' . hash('sha256', $gid)] = ['groupId' => null,
                        'displayName' => mb_convert_encoding($group->getDisplayName(), 'UTF-8', 'UTF-8'),
                        'invalidId' => true, 'trusted' => null, 'exists' => null, 'state' => null, 'grants' => null];
                    continue;
                }
                $all[IdentityKey::group($gid)] = ['groupId' => $gid, 'displayName' => $group->getDisplayName()];
            }
            foreach (['mcp_group_grants', 'mcp_group_life'] as $table) {
                $result = $this->db->getQueryBuilder()->select('group_key', 'group_id')->from($table)->executeQuery();
                try {
                    while (($row = $result->fetch()) !== false) {
                        if (!is_string($row['group_id']) || $row['group_key'] !== IdentityKey::group($row['group_id'])) {
                            throw new PolicyUnavailable('Invalid stored group identity');
                        }
                        $all[$row['group_key']] ??= ['groupId' => $row['group_id'], 'displayName' => $row['group_id']];
                        if ($table === 'mcp_group_grants') {
                            $granted[$row['group_id']] = true;
                        }
                    }
                } finally {
                    $result->closeCursor();
                }
            }
            $all = array_values(array_filter($all, static fn (array $row): bool => $search === ''
                || mb_stripos((string)$row['groupId'], trim($search)) !== false || mb_stripos($row['displayName'], trim($search)) !== false));
            usort($all, static fn (array $a, array $b): int => strnatcasecmp($a['displayName'], $b['displayName'])
                ?: strcmp((string)$a['groupId'], (string)$b['groupId']));
            $withGrants = array_values(array_filter($all, static fn (array $row): bool => isset($granted[(string)$row['groupId']])));
            $counts = ['all' => count($all), 'granted' => count($withGrants), 'blocked' => null];
            $classified = [];
            if ($filter === 'granted') {
                $all = $withGrants;
            } elseif ($filter === 'blocked') {
                $all = array_values(array_filter($all, function (array $row) use (&$classified): bool {
                    if ($row['groupId'] === null) {
                        return false;
                    }
                    try {
                        $classified[$row['groupId']] = $this->classifier->classify($row['groupId']);
                    } catch (PolicyUnavailable|\InvalidArgumentException) {
                        $classified[$row['groupId']] = ['trusted' => null, 'exists' => null];
                    }
                    return $classified[$row['groupId']]['trusted'] === false;
                }));
                $counts['blocked'] = count($all);
            }
            $pageRows = array_slice($all, ($page - 1) * 50, 50);
            $gids = array_values(array_filter(array_column($pageRows, 'groupId'), static fn ($gid): bool => $gid !== null));
            $states = $this->states($gids, $classified);
            $rows = array_map(static fn (array $row): array => $row['groupId'] === null
                ? $row : $row + $states[IdentityKey::group($row['groupId'])], $pageRows);
            return ['groups' => $rows, 'page' => $page, 'pageSize' => 50, 'hasMore' => count($all) > $page * 50,
                'total' => count($all), 'filter' => $filter, 'counts' => $counts];
        });
    }

    public function detail(string $gid): array {
        return $this->read(function () use ($gid): array {
            $policy = $this->states([$gid])[IdentityKey::group($gid)];
            $policy['catalog'] = ['_system' => ['eligible']] + GrantPolicy::CATALOG;
            $counts = ['members' => null, 'overrides' => null, 'permissions' => null];
            try {
                $group = $this->groups->get($gid);
                $count = $group?->count();
                $counts['members'] = $policy['exists'] === false ? 0 : (is_int($count) && $count >= 0 ? $count : null);
                if ($policy['trusted']) {
                    $members = $this->classifier->members($gid);
                    // A backend without a reliable total cannot prove complete enumeration for statistics.
                    if ($counts['members'] !== null && count($members) === $counts['members']) {
                        $stats = $this->statisticsForMembers(array_values($members));
                        $counts['overrides'] = $stats['overrides'];
                        $counts['permissions'] = $stats['permissions'];
                    }
                }
            } catch (Throwable) {
                // Never present partial counters as complete after an unreadable member or policy.
                $counts = ['members' => null, 'overrides' => null, 'permissions' => null];
            }
            return $policy + ['counts' => $counts];
        });
    }

    /**
     * Stored policy reads are batched; each source classification supplies both trusted and exists.
     *
     * @param array<string, array{trusted: ?bool, exists: ?bool}> $classified classifications already read by the caller
     */
    private function states(array $gids, array $classified = []): array {
        $lifecycles = [];
        foreach ($this->life->forGroups($gids) as $life) {
            $lifecycles[IdentityKey::group($life['groupId'])] = $life['state'];
        }
        $states = [];
        foreach ($this->grants->forGroups($gids) as $policy) {
            $gid = $policy['groupId'];
            $key = IdentityKey::group($gid);
            try {
                $classification = $classified[$gid] ?? $this->classifier->classify($gid);
            } catch (PolicyUnavailable|\InvalidArgumentException) {
                $classification = ['trusted' => null, 'exists' => null];
            }
            $states[$key] = $policy + $classification + ['state' => $lifecycles[$key] ?? null];
        }
        return $states;
    }

    private function statisticsForMembers(array $members): array {
        $permissions = [];
        foreach (['_system' => ['eligible']] + GrantPolicy::CATALOG as $module => $operations) {
            foreach ($operations as $operation) {
                $permissions[$module][$operation] = ['overrides' => 0, 'different' => 0];
            }
        }
        $unique = 0;
        foreach (array_chunk($members, 200) as $chunk) {
            $contexts = $this->resolver->beginManyGrants($chunk);
            try {
                foreach ($contexts as $context) {
                    $any = false;
                    foreach ($permissions as $module => $operations) {
                        foreach ($operations as $operation => $_) {
                            $resolved = $module === '_system' ? $context->eligible() : $context->granted($module, $operation);
                            if (!$resolved->rawState['present']) {
                                continue;
                            }
                            $any = true;
                            ++$permissions[$module][$operation]['overrides'];
                            $inherited = $context->inherited($module, $operation)->allowed;
                            if ($inherited === null) {
                                $permissions[$module][$operation]['different'] = null;
                            } elseif ($resolved->allowed !== $inherited && $permissions[$module][$operation]['different'] !== null) {
                                ++$permissions[$module][$operation]['different'];
                            }
                        }
                    }
                    $unique += (int)$any;
                }
            } finally {
                foreach ($contexts as $context) {
                    $context->close();
                }
            }
        }
        return ['overrides' => $unique, 'permissions' => $permissions];
    }

    /** Keep each returned revision paired with its data; a concurrent writer gets one bounded re-read. */
    private function read(callable $read): array {
        try {
            if ($this->db->inTransaction()) {
                throw new PolicyUnavailable('Directory reads cannot run inside a transaction');
            }
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                $before = $this->state->state()['revision'];
                $result = $read();
                if ($before === $this->state->state()['revision']) {
                    return $result + ['revision' => $before];
                }
            }
            throw new PolicyUnavailable('Policy changed during directory read');
        } catch (\InvalidArgumentException|PolicyUnavailable $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PolicyUnavailable('Cannot read group directory', previous: $e);
        }
    }
}
