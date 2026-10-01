<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;

/**
 * Decides which calendar operations are exposed, from the verification the selftest recorded.
 *
 * The writes go through the real CalDAV pipeline, which cannot be exercised off the server, so they
 * stay hidden until `occ mcp:calendar-selftest` has run there and written a record. The record is
 * valid only for the app version and the Nextcloud major.minor it was produced on: an upgrade
 * re-hides the writes until the selftest runs again, because either may have changed the pipeline.
 * A patch release of the Nextcloud keeps the verification, so a security update does not silently
 * switch the calendar writes off.
 *
 * The stored grants are never touched by this class. It only decides what the module exposes.
 */
final class CalendarWriteGate {
    /** App that owns the record. */
    public const APP_ID = 'mcp';
    /** Appconfig key holding the verification record. */
    public const CONFIG_KEY = 'calendar_writes_verified';
    /** Operation always available, granted or not. */
    public const READ = 'read';
    /** Writable grant operations accepted in a verification record. */
    private const WRITES = ['create', 'edit', 'move', 'delete', 'transfer'];
    /** Number of leading version segments compared on the Nextcloud side. */
    private const NC_SEGMENTS = 2;

    /**
     * @param IAppConfig $appConfig holds the verification record
     * @param IAppManager $appManager answers the installed app version, compared exactly
     * @param IConfig $config answers the Nextcloud version, compared on major.minor
     */
    public function __construct(
        private IAppConfig $appConfig,
        private IAppManager $appManager,
        private IConfig $config,
    ) {}

    /**
     * @return list<string> grant operations the calendar module may expose right now
     */
    public function operations(): array {
        $record = $this->validRecord();
        return $record === null ? [self::READ] : [self::READ, ...array_values($record['operations'])];
    }

    /**
     * @return bool whether sending invitations has been proved on this server
     */
    public function invitationsVerified(): bool {
        $record = $this->validRecord();
        return $record !== null && ($record['invitations'] ?? false) === true;
    }

    /**
     * @return array<string, mixed>|null the record as stored, valid for the current versions
     */
    public function record(): ?array {
        return $this->validRecord();
    }

    /**
     * Stores a verification record. Only the selftest calls this, and only after every required
     * step passed on the real server.
     *
     * @param list<string> $operations operations the selftest proved
     * @param string $uid account the selftest ran as
     * @param string $at ISO timestamp of the run
     * @param bool $invitations whether internal scheduling was verified
     * @return void
     */
    public function recordVerification(array $operations, string $uid, string $at, bool $invitations = false): void {
        $this->appConfig->setValueString(self::APP_ID, self::CONFIG_KEY, json_encode([
            'app' => $this->appManager->getAppVersion(self::APP_ID),
            'nextcloud' => $this->nextcloudMajorMinor(),
            'operations' => array_values($operations),
            'invitations' => $invitations,
            'uid' => $uid,
            'at' => $at,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return void drops any verification, which re-hides every write
     */
    public function revoke(): void {
        $this->appConfig->deleteKey(self::APP_ID, self::CONFIG_KEY);
    }

    /**
     * @return array<string, mixed>|null the stored record, or null when absent or stale
     */
    private function validRecord(): ?array {
        $raw = $this->appConfig->getValueString(self::APP_ID, self::CONFIG_KEY, '');
        if ($raw === '') {
            return null;
        }
        try {
            $record = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($record) || !isset($record['app'], $record['nextcloud'], $record['operations']) || !is_array($record['operations'])) {
            return null;
        }
        if (!array_is_list($record['operations']) || !is_bool($record['invitations'] ?? false)) {
            return null;
        }
        foreach ($record['operations'] as $operation) {
            if (!is_string($operation) || !in_array($operation, self::WRITES, true)) {
                return null;
            }
        }
        if ($this->nextcloudMajorMinor() === '' || $this->appManager->getAppVersion(self::APP_ID) === '') {
            return null;
        }
        if ($record['app'] !== $this->appManager->getAppVersion(self::APP_ID)) {
            return null;
        }
        return $record['nextcloud'] === $this->nextcloudMajorMinor() ? $record : null;
    }

    /**
     * @return string the server version truncated to major.minor, e.g. "33.0" for "33.0.2.2"
     */
    private function nextcloudMajorMinor(): string {
        $version = $this->config->getSystemValueString('version');
        if (preg_match('/^\d+\.\d+(?:\.\d+)*$/D', $version) !== 1) {
            return '';
        }
        $parts = explode('.', $version);
        return implode('.', array_slice($parts, 0, self::NC_SEGMENTS));
    }
}
