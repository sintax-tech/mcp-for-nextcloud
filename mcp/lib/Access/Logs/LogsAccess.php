<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Logs;

use InvalidArgumentException;
use OCA\Mcp\Db\LogsGroupsMapper;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Access\Policy\Resolution\LogsRoleReader;
use OCA\Mcp\Access\Grants\Groups\MembershipReader;
use OCA\Mcp\Access\Policy\Transactions\PolicyConflict;
use OCA\Mcp\Access\Policy\Events\PolicyEventPayload;
use OCA\Mcp\Access\Policy\Events\PolicyOutbox;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\Transactions\PolicyTransaction;
use OCA\Mcp\Access\Policy\Transactions\SnapshotInvalidated;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Role gate of the logs module, checked before the per-user grant: the server log shows the file names, URLs and
 * accounts of every user, so only a Nextcloud administrator or a member of a group the administrator listed in the
 * MCP settings may read it. An empty list means administrators only.
 *
 * The gate also closes while the log is not a file: syslog, errorlog and systemd keep nothing the core can read back.
 *
 * The role is read fresh on every check (contracts §4.2): the membership from the group backends (MembershipReader)
 * and the administrator role from LogsRoleReader, never from the memoized IGroupManager::isAdmin/isInGroup, so a worker
 * reusing this service never keeps a role of an earlier operation. One deliberate difference with those calls: group
 * ids compare strictly ("1" and "01" are two groups), as LogsGroupsMapper stores them.
 */
class LogsAccess {
    /** Legacy appconfig key, used only for the one-time import. */
    public const GROUPS_KEY = LogsGroupsMapper::LEGACY_KEY;
    /** Extra coordination lock; database CAS protects even with a Noop locking provider. */
    public const LOCK_KEY = 'mcp/logs_groups';
    /** Values of the log_type system setting whose writer keeps no file, as the core's LogFactory names them. */
    public const NON_FILE_TYPES = ['errorlog', 'syslog', 'systemd'];

    /**
     * @param IConfig $config system settings (log_type)
     * @param IGroupManager $groupManager existence of the listed groups
     * @param LogsGroupsMapper $mapper uncached, versioned role state
     * @param ILockingProvider $locks the core's locking provider, shared by every worker
     * @param PolicyTransaction $writer owner of the transaction: a role change also advances the global revision
     * @param LogsRoleReader $roles the role on a fresh membership
     * @param MembershipReader $membership fresh group membership, from the backends
     * @param IUserManager $users the account the membership is read for
     */
    public function __construct(
        private IConfig $config,
        private IGroupManager $groupManager,
        private LogsGroupsMapper $mapper,
        private ILockingProvider $locks,
        private PolicyTransaction $writer,
        private LogsRoleReader $roles,
        private MembershipReader $membership,
        private IUserManager $users,
    ) {}

    /** @return string the log_type system setting, lowercase; 'file' when unset */
    public function logType(): string {
        return strtolower($this->config->getSystemValueString('log_type', 'file'));
    }

    /**
     * Any value but the non-file ones is the file writer: the core's LogFactory falls back to it for an unknown type.
     *
     * @return bool whether the server writes its log to a file the core can read back
     */
    public function available(): bool {
        return self::fileLog($this->config);
    }

    /**
     * @param IConfig $config system settings
     * @return bool whether log_type names the file writer (any value but the non-file ones)
     */
    public static function fileLog(IConfig $config): bool {
        return !in_array(strtolower($config->getSystemValueString('log_type', 'file')), self::NON_FILE_TYPES, true);
    }

    /**
     * @param string $uid Nextcloud user id
     * @return bool whether the log is a file and the user is an administrator or a member of a listed group
     * @throws PolicyUnavailable when the membership cannot be read; never a permissive or empty default
     */
    public function permits(string $uid): bool {
        if (!$this->available()) {
            return false;
        }
        $user = $this->users->get($uid);
        if ($user === null) {
            return false;
        }
        // A group deleted after it was listed has no members, so it stops matching on its own.
        return $this->roles->read($uid, $this->membership->read($user))['permitted'];
    }

    /** @return list<string> the group ids listed by the administrator, canonical; a garbled value reads as none */
    public function groups(): array {
        return $this->state()['groups'];
    }

    /** A single database snapshot: the GET must pair groups with the version of that exact list. */
    public function state(): array {
        return $this->mapper->state();
    }

    /** @return list<string> listed group ids whose group no longer exists, so the page can offer their removal */
    public function missingGroups(?array $groups = null): array {
        return array_values(array_filter($groups ?? $this->groups(), fn (string $gid): bool => $this->groupManager->get($gid) === null));
    }

    /**
     * Replaces the list. A group not listed yet must exist; a listed group may stay or go even after it was deleted,
     * so a deleted group never locks the list. Empty lists stay in the row, with their incremented version.
     *
     * The change runs through the policy writer, so the role list and the global revision move in one transaction
     * (contracts §2.1): the group lookups (possibly remote) happen before the mutex, and under it only the list's own
     * version CAS, the revision CAS and the audit event run. Saving the stored list again is a no-op that advances
     * neither counter. The lock is an extra layer; the database CAS is authoritative even when file locking is off.
     *
     * @param list<string> $gids group ids
     * @param list<string>|null $previous the list the caller changed; when it differs from the stored one, another
     *     save came first and nothing is written. Null skips the check (internal callers).
     * @param int|null $version expected list version; required by PUT, internal callers use their current read
     * @param string|null $expectedRevision global policy revision the page was built from; required by PUT, null for
     *     internal callers, which run at the current revision
     * @param string|null $actor administrator uid recorded in the audit event; null for internal callers
     * @throws InvalidArgumentException for an id or a previous id that is not a non-empty string, a new group that
     *     does not exist, or an invalid revision
     * @throws LogsGroupsConflict when $previous or $version is not the stored one, or another save holds the lock
     * @throws PolicyConflict when $expectedRevision is stale
     * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain ($uncertain = true)
     */
    public function setGroups(array $gids, ?array $previous = null, ?int $version = null, ?string $expectedRevision = null, ?string $actor = null): void {
        foreach ([...$gids, ...($previous ?? [])] as $gid) {
            if (!is_string($gid) || $gid === '') {
                throw new InvalidArgumentException('Invalid request');
            }
        }
        $gids = self::canonical($gids);
        try {
            $this->locks->acquireLock(self::LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);
        } catch (LockedException) {
            throw new LogsGroupsConflict('Another save of the groups is running');
        }
        try {
            // Outside the mutex: the stored list it was checked against, and whether each new group exists.
            $prepare = function () use ($gids): array {
                $observed = $this->mapper->state();
                foreach ($gids as $gid) {
                    if (!in_array($gid, $observed['groups'], true) && $this->groupManager->get($gid) === null) {
                        throw new InvalidArgumentException('Invalid request');
                    }
                }
                return $observed;
            };
            $apply = function (array $observed, \OCA\Mcp\Access\Policy\Transactions\PolicyAttempt $attempt) use ($gids, $previous, $version, $actor): bool {
                $current = $attempt->state();
                $outbox = $attempt->outbox();
                $state = $this->mapper->state();
                if ($version !== null && $version !== $state['version']) {
                    throw new LogsGroupsConflict('The groups changed since they were read');
                }
                if ($previous !== null && self::canonical($previous) !== $state['groups']) {
                    throw new LogsGroupsConflict('The groups changed since they were read');
                }
                if ($state !== $observed) {
                    // Another save landed between the existence checks and the mutex: check again.
                    throw new SnapshotInvalidated();
                }
                if ($gids === $state['groups']) {
                    return false;
                }
                $this->mapper->compareAndSet($gids, $state['version']);
                $outbox->add(PolicyEventsMapper::KIND_AUDIT, PolicyEventPayload::audit($actor, PolicyEventPayload::ACTION_LOGS,
                    $current['revision'], \OCA\Mcp\Access\Policy\DecimalCounter::increment($current['revision']), null, null,
                    [['uid' => null, 'key' => 'logs_groups', 'before' => json_encode($state['groups'], JSON_THROW_ON_ERROR), 'after' => json_encode($gids, JSON_THROW_ON_ERROR)]]));
                return true;
            };
            $expectedRevision === null
                ? $this->writer->runInternal([], $prepare, $apply)
                : $this->writer->runAdmin($expectedRevision, [], $prepare, $apply);
        } finally {
            $this->locks->releaseLock(self::LOCK_KEY, ILockingProvider::LOCK_EXCLUSIVE);
        }
    }

    /**
     * The one form a list of group ids is stored and compared in: strings, without repetition, sorted as strings, so
     * "1" and "01" stay two groups.
     *
     * @param array<mixed> $gids group ids, already checked to be strings
     * @return list<string> the canonical list
     */
    private static function canonical(array $gids): array {
        return LogsGroupsMapper::canonical($gids);
    }
}
