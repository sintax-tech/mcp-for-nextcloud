<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Integration;

use OCA\Mcp\Access\AppAvailability\AppEnablement;
use OCP\App\IAppManager;
use OCP\IUserManager;

class TalkAvailability
{
    public const APP_ID = 'spreed';
    public function __construct(private IAppManager $appManager, private IUserManager $userManager) {}
    /** Whether the optional spreed app is enabled for the user on this very call. */
    public function isEnabledFor(string $userId): bool
    {
        // isEnabledForUser() takes an IUser, never the UID string: passing the string breaks at runtime.
        $user = $this->userManager->get($userId);
        if ($user === null) {
            return false;
        }
        // A Talk older than MCP supports (AppEnablement::MINIMUM_VERSIONS) counts as off.
        return AppEnablement::forUser($this->appManager, self::APP_ID, $user);
    }
}
