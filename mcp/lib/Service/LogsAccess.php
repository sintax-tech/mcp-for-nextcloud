<?php
declare(strict_types=1);

namespace OCA\Mcp\Service;

use InvalidArgumentException;
use OCP\IAppConfig;
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
    /** App config key of the groups whose members may read the log, a JSON list of group ids. */
    public const GROUPS_KEY = 'logs_groups';
    /** Lock that makes read, compare and write of GROUPS_KEY one step across workers. */
    public const LOCK_KEY = 'mcp/logs_groups';
    /** Values of the log_type system setting whose writer keeps no file, as the core's LogFactory names them. */
    public const NON_FILE_TYPES = ['errorlog', 'syslog', 'systemd'];

    /**
     * @param IConfig $config system settings (log_type)
     * @param IGroupManager $groupManager administrators and group membership
     * @param IAppConfig $appConfig where GROUPS_KEY is stored; its request cache is cleared before a save compares
     * @param ILockingProvider $locks the core's locking provider, shared by every worker
     */
    public function __construct(
        private IConfig $config,
        private IGroupManager $groupManager,
        private IAppConfig $appConfig,
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
        $decoded = json_decode($this->appConfig->getValueString(GrantPolicy::APP, self::GROUPS_KEY, '[]'), true);
        if (!is_array($decoded)) {
            return [];
        }
        return self::canonical(array_filter($decoded, static fn ($gid): bool => is_string($gid) && $gid !== ''));
    }

    /** @return list<string> listed group ids whose group no longer exists, so the page can offer their removal */
    public function missingGroups(): array {
        return array_values(array_filter($this->groups(), fn (string $gid): bool => $this->groupManager->get($gid) === null));
    }

    /**
     * Replaces the list. A group not listed yet must exist; a listed group may stay or go even after it was deleted,
     * so a deleted group never locks the list. An empty list removes the key.
     *
     * Read, compare and write are one step for every worker: they run under the exclusive LOCK_KEY lock of the core's
     * locking provider, and the stored list is read again inside it with the request cache cleared, so two saves that
     * started from the same list can never both write. A lock held by another save is a conflict, not a wait.
     *
     * @param list<string> $gids group ids
     * @param list<string>|null $previous the list the caller changed; when it differs from the stored one, another
     *     save came first and nothing is written. Null skips the check (internal callers).
     * @throws InvalidArgumentException for an id or a previous id that is not a non-empty string, or a new group
     *     that does not exist
     * @throws LogsGroupsConflict when $previous is not the stored list or another save holds the lock
     */
    public function setGroups(array $gids, ?array $previous = null): void {
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
            // Another worker may have written since this request read the list: read the database, not the cache.
            $this->appConfig->clearCache();
            $stored = $this->groups();
            if ($previous !== null && self::canonical($previous) !== $stored) {
                throw new LogsGroupsConflict('The groups changed since they were read');
            }
            foreach ($gids as $gid) {
                if (!in_array($gid, $stored, true) && $this->groupManager->get($gid) === null) {
                    throw new InvalidArgumentException('Invalid request');
                }
            }
            if ($gids === []) {
                $this->appConfig->deleteKey(GrantPolicy::APP, self::GROUPS_KEY);
                return;
            }
            $this->appConfig->setValueString(GrantPolicy::APP, self::GROUPS_KEY, json_encode($gids, JSON_THROW_ON_ERROR));
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
        $gids = array_values(array_unique(array_map('strval', $gids), SORT_STRING));
        sort($gids, SORT_STRING);
        return $gids;
    }
}
