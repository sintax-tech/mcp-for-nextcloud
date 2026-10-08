<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service;

use InvalidArgumentException;
use OCA\Mcp\Db\LogsGroupsMapper;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Role gate of the logs module, checked before the per-user grant: the server log shows the file names, URLs and
 * accounts of every user, so only a Nextcloud administrator or a member of a group the administrator listed in the
 * MCP settings may read it. An empty list means administrators only.
 *
 * The gate also closes while the log is not a file: syslog, errorlog and systemd keep nothing the core can read back.
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
     * @param IGroupManager $groupManager administrators and group membership
     * @param LogsGroupsMapper $mapper uncached, versioned role state
     * @param ILockingProvider $locks the core's locking provider, shared by every worker
     */
    public function __construct(
        private IConfig $config,
        private IGroupManager $groupManager,
        private LogsGroupsMapper $mapper,
        private ILockingProvider $locks,
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
        return !in_array($this->logType(), self::NON_FILE_TYPES, true);
    }

    /**
     * @param string $uid Nextcloud user id
     * @return bool whether the log is a file and the user is an administrator or a member of a listed group
     */
    public function permits(string $uid): bool {
        if (!$this->available()) {
            return false;
        }
        if ($this->groupManager->isAdmin($uid)) {
            return true;
        }
        foreach ($this->groups() as $gid) {
            // A group deleted after it was listed has no members, so it stops matching on its own.
            if ($this->groupManager->isInGroup($uid, $gid)) {
                return true;
            }
        }
        return false;
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
     * The lock is an extra layer; an atomic database CAS is authoritative even when file locking is disabled.
     *
     * @param list<string> $gids group ids
     * @param list<string>|null $previous the list the caller changed; when it differs from the stored one, another
     *     save came first and nothing is written. Null skips the check (internal callers).
     * @param int|null $version expected database version; required by PUT, internal callers use their current read
     * @throws InvalidArgumentException for an id or a previous id that is not a non-empty string, or a new group
     *     that does not exist
     * @throws LogsGroupsConflict when $previous is not the stored list or another save holds the lock
     */
    public function setGroups(array $gids, ?array $previous = null, ?int $version = null): void {
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
            $state = $this->mapper->state();
            $stored = $state['groups'];
            $version ??= $state['version'];
            if ($version !== $state['version']) {
                throw new LogsGroupsConflict('The groups changed since they were read');
            }
            if ($previous !== null && self::canonical($previous) !== $stored) {
                throw new LogsGroupsConflict('The groups changed since they were read');
            }
            foreach ($gids as $gid) {
                if (!in_array($gid, $stored, true) && $this->groupManager->get($gid) === null) {
                    throw new InvalidArgumentException('Invalid request');
                }
            }
            $this->mapper->compareAndSet($gids, $version);
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
