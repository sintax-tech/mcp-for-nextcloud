<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Grants\Groups;

use OCA\Mcp\Access\Policy\PolicyUnavailable;

use OCA\Mcp\Db\IdentityKey;
use OCP\IGroupManager;
use OCP\IUser;
use Throwable;

/** No manager memoization in fresh mode. Backend-private caches remain process-scoped. */
class MembershipReader {
    public function __construct(private IGroupManager $groups) {}

    /**
     * @param list<string> $added event overlay, exact GIDs
     * @param list<string> $removed event overlay, exact GIDs (removal wins)
     * @return list<string> sorted bytewise; aggregation alone never grants
     */
    public function read(IUser $user, bool $fresh = true, array $added = [], array $removed = []): array {
        try {
            $ids = [];
            if ($fresh) {
                foreach ($this->groups->getBackends() as $backend) {
                    $found = $backend->getUserGroups($user->getUID());
                    if (!is_array($found) || !array_is_list($found)) {
                        throw new \UnexpectedValueException('Invalid backend membership');
                    }
                    array_push($ids, ...$found);
                }
            } else {
                $ids = $this->groups->getUserGroupIds($user);
            }
            $unique = [];
            foreach ([...$ids, ...$added] as $gid) {
                if (!is_string($gid)) {
                    throw new \UnexpectedValueException('Invalid backend group id');
                }
                $unique[IdentityKey::group($gid)] = $gid;
            }
            foreach ($removed as $gid) {
                unset($unique[IdentityKey::group($gid)]);
            }
            $ids = array_values($unique);
            sort($ids, SORT_STRING);
            return $ids;
        } catch (Throwable $e) {
            throw new PolicyUnavailable('Cannot read group membership', previous: $e);
        }
    }
}
