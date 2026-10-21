<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Contacts\Directory;

use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
class DirectoryVisibility
{
    private const GUESTS = 'Guests';
    public function __construct(
        private IAppConfig $config,
        private IGroupManager $groups,
        private IUserManager $users,
    )
    {
    }
    public function visible(string $userId, string $accountId): bool
    {
        if ($accountId === $userId) {
            return true;
        }
        $viewer = $this->users->get($userId);
        if ($viewer === null || $viewer->getBackendClassName() === self::GUESTS) {
            return false;
        }
        if (!$this->enumeration()) {
            // Only exact full matches reach this point; the core allows them regardless of groups.
            return true;
        }
        if ($this->flag('shareapi_restrict_user_enumeration_to_group', false)) {
            return $this->sharesGroup($viewer, $accountId);
        }
        // Phone-book enumeration relies on a core service that is not public API: show only the user itself.
        return !$this->flag('shareapi_restrict_user_enumeration_to_phone', false);
    }
    public function sharesGroup(IUser $viewer, string $accountId): bool
    {
        foreach ($this->groups->getUserGroupIds($viewer) as $groupId) {
            if ($this->groups->isInGroup($accountId, $groupId)) {
                return true;
            }
        }
        return false;
    }
    public function enumeration(): bool
    {
        return $this->flag('shareapi_allow_share_dialog_user_enumeration', true);
    }
    public function fullMatch(): bool
    {
        return $this->flag('shareapi_restrict_user_enumeration_full_match', true);
    }
    public function flag(string $key, bool $default): bool
    {
        return $this->config->getValueString('core', $key, $default ? 'yes' : 'no') === 'yes';
    }
}
