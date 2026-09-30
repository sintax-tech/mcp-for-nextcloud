<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

use OCP\IConfig;

/**
 * Tells whether a deleted calendar object goes to the CalDAV trash and stays recoverable.
 *
 * Mirrors nextcloud/server stable33: RetentionService::RETENTION_CONFIG_KEY is
 * 'calendarRetentionObligation' (apps/dav/lib/CalDAV/RetentionService.php line 17), and
 * CalDavBackend::deleteCalendarObject deletes permanently when that dav value is '0' (line 1745).
 */
class TrashPolicy {
    /** App id holding the retention setting. */
    private const APP = 'dav';
    /** Retention setting key, in seconds. */
    private const KEY = 'calendarRetentionObligation';

    /**
     * @param IConfig $config Nextcloud configuration
     */
    public function __construct(private IConfig $config) {}

    /**
     * @return bool false when retention is disabled and deletion would be permanent
     */
    public function recoverable(): bool {
        return $this->config->getAppValue(self::APP, self::KEY) !== '0';
    }
}
