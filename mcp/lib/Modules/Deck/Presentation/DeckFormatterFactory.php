<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Presentation;

use OCP\IURLGenerator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCA\Mcp\Platform\Time\UserTimezone;

class DeckFormatterFactory {
    public function __construct(
        private IURLGenerator $urls,
        private ITimeFactory $clock,
        private ?IUserManager $users = null,
        private ?UserTimezone $zones = null,
    ) {
    }

    public function forUser(string $userId): DeckFormatter {
        return new DeckFormatter($this->urls, $this->clock, $this->users, $this->zones?->forUser($userId));
    }
}
