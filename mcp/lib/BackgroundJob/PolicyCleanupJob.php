<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\BackgroundJob;

use OCA\Mcp\Access\Policy\Events\PolicyEventProcessor;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Daily housekeeping, registered by the app in appinfo/info.xml and run by the server's own background job runner
 * (AJAX, webcron or cron, whatever the administrator chose), so nothing extra is needed: expired OAuth credentials,
 * pending credential cleanups and audit deliveries, expired receipts. Idempotent; the app stays correct when it does
 * not run, because validation refuses expired and revoked credentials and token requests flush a bounded batch.
 */
class PolicyCleanupJob extends TimedJob {
    public const INTERVAL = 86400;

    public function __construct(ITimeFactory $time, private PolicyEventProcessor $processor) {
        parent::__construct($time);
        $this->setInterval(self::INTERVAL);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    /** @param mixed $argument unused */
    protected function run($argument): void {
        $this->processor->maintenance();
    }
}
