<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Access;

use OCP\App\IAppManager;
use OCP\IUserManager;
use OCA\Mcp\Access\AppAvailability\AppEnablement;
use OCA\Mcp\Modules\Deck\Execution\ErrorMessages;

class DeckAvailability {
    public function __construct(private IAppManager $apps, private ?IUserManager $users = null) {
    }

    public function assertFor(string $userId): void {
        $user = $this->users?->get($userId);
        if ($this->users !== null && $user === null || !AppEnablement::forUser($this->apps, 'deck', $user)) {
            throw new \InvalidArgumentException(ErrorMessages::errorDeckAppUnavailable());
        }
    }
}
