<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Grants\Groups;

use OCA\Mcp\Access\Policy\PolicyUnavailable;

use OCP\Group\Backend\INamedBackend;
use OCP\GroupInterface;
use OCP\IGroupManager;
use Throwable;

/** Uses public backends, never a cached IGroup. Duplicate backend names deliberately count twice. */
class GroupSourceClassifier {
    public function __construct(private IGroupManager $groups) {}

    /**
     * Whether the group may grant (contracts §3.3.5): served by exactly one backend, named 'Database'.
     *
     * @throws PolicyUnavailable when a backend cannot be read
     */
    public function trusted(string $gid): bool {
        return $this->classify($gid)['trusted'];
    }

    /** Read the source once for both diagnostics. @return array{trusted:bool, exists:bool} */
    public function classify(string $gid): array {
        $serving = $this->serving($gid);
        return ['trusted' => count($serving) === 1 && $serving[0] instanceof INamedBackend
            && $serving[0]->getBackendName() === 'Database', 'exists' => $serving !== []];
    }

    /**
     * Whether any backend registered in this process serves the group: the fresh existence read of the lifecycle
     * job. Database::groupExists keeps a positive cache, so a long process may still see a group deleted elsewhere.
     *
     * @throws PolicyUnavailable when a backend cannot be read
     */
    public function exists(string $gid): bool {
        return $this->serving($gid) !== [];
    }

    /**
     * The uids of the group's members, from the backends that serve it (one call each, not one per user): the cheap
     * side of the intersection a group deletion makes with the credential holders.
     *
     * @return array<string, string> uid by a digest of the uid, never keyed by the uid itself
     * @throws PolicyUnavailable when a backend cannot be read or answers an invalid list
     */
    public function members(string $gid): array {
        try {
            $members = [];
            foreach ($this->serving($gid) as $backend) {
                $uids = $backend->usersInGroup($gid);
                if (!is_array($uids)) {
                    throw new \UnexpectedValueException('Invalid backend member list');
                }
                foreach ($uids as $uid) {
                    if (!is_string($uid)) {
                        throw new \UnexpectedValueException('Invalid backend member id');
                    }
                    $members[hash('sha256', $uid)] = $uid;
                }
            }
            return $members;
        } catch (PolicyUnavailable $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PolicyUnavailable('Cannot list group members', previous: $e);
        }
    }

    /**
     * @return list<GroupInterface> the backends that answer groupExists($gid) with true
     * @throws PolicyUnavailable when a backend is invalid or cannot be read
     */
    private function serving(string $gid): array {
        try {
            $serving = [];
            foreach ($this->groups->getBackends() as $backend) {
                if (!$backend instanceof GroupInterface) {
                    throw new \UnexpectedValueException('Invalid group backend');
                }
                $exists = $backend->groupExists($gid);
                if (!is_bool($exists)) {
                    throw new \UnexpectedValueException('Invalid backend existence result');
                }
                if ($exists) {
                    $serving[] = $backend;
                }
            }
            return $serving;
        } catch (Throwable $e) {
            throw new PolicyUnavailable('Cannot classify group source', previous: $e);
        }
    }
}
