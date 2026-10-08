<?php
declare(strict_types=1);

namespace OCA\Mcp\Service;

use InvalidArgumentException;
use OCP\IConfig;
use OCP\IGroupManager;

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
    /** Values of the log_type system setting whose writer keeps no file, as the core's LogFactory names them. */
    public const NON_FILE_TYPES = ['errorlog', 'syslog', 'systemd'];

    public function __construct(private IConfig $config, private IGroupManager $groupManager) {}

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

    /** @return list<string> the group ids listed by the administrator; a garbled value reads as none */
    public function groups(): array {
        $decoded = json_decode((string)$this->config->getAppValue(GrantPolicy::APP, self::GROUPS_KEY, '[]'), true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter($decoded, static fn ($gid): bool => is_string($gid) && $gid !== ''));
    }

    /** @return list<string> listed group ids whose group no longer exists, so the page can offer their removal */
    public function missingGroups(): array {
        return array_values(array_filter($this->groups(), fn (string $gid): bool => $this->groupManager->get($gid) === null));
    }

    /**
     * Replaces the list. A group not listed yet must exist; a listed group may stay or go even after it was deleted,
     * so a deleted group never locks the list. Everything is checked before anything is written, and an empty list
     * removes the key.
     *
     * @param list<string> $gids group ids
     * @param list<string>|null $previous the list the caller changed; when it differs from the stored one, another
     *     save came first and nothing is written. Null skips the check (tests and internal callers).
     * @throws InvalidArgumentException for an id that is not a string or a new group that does not exist
     * @throws LogsGroupsConflict when $previous is not the stored list
     */
    public function setGroups(array $gids, ?array $previous = null): void {
        $stored = $this->groups();
        if ($previous !== null && self::normalized($previous) !== self::normalized($stored)) {
            throw new LogsGroupsConflict('The groups changed since they were read');
        }
        $gids = array_values(array_unique($gids));
        foreach ($gids as $gid) {
            if (!is_string($gid) || $gid === '' || (!in_array($gid, $stored, true) && $this->groupManager->get($gid) === null)) {
                throw new InvalidArgumentException('Invalid request');
            }
        }
        sort($gids);
        if ($gids === []) {
            $this->config->deleteAppValue(GrantPolicy::APP, self::GROUPS_KEY);
            return;
        }
        $this->config->setAppValue(GrantPolicy::APP, self::GROUPS_KEY, json_encode($gids, JSON_THROW_ON_ERROR));
    }

    /** @param list<mixed> $gids @return list<mixed> the ids sorted and without repetition, to compare two lists */
    private static function normalized(array $gids): array {
        $gids = array_values(array_unique($gids, SORT_REGULAR));
        sort($gids);
        return $gids;
    }
}
