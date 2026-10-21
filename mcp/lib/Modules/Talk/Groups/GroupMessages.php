<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Groups;

use OCA\Mcp\L10n\Translator;

final class GroupMessages
{
    /**
     * Reported per guest in invitations_failed, after the group already exists.
     * @return string The message in the language of the current user.
     */
    public static function invitationFailed(): string
    {
        return Translator::t('Talk did not accept the invitation of this participant');
    }

    /**
     * Talk did not create the room.
     * @return string The message in the language of the current user.
     */
    public static function conversationNotCreated(): string
    {
        return Translator::t('conversation not created by Talk');
    }

    /**
     * The group name is blank once normalized.
     * @return string The message in the language of the current user.
     */
    public static function invalidGroupName(): string
    {
        return Translator::t('the group name cannot be empty');
    }

    /**
     * The group name is over the module character limit.
     * @return string The message in the language of the current user.
     */
    public static function groupNameTooLong(): string
    {
        return Translator::t('the group name exceeds the character limit');
    }

    /**
     * The group would invite more people than the module accepts.
     *
     * @param int $max Largest number of guests accepted
     * @return string The message in the language of the current user
     */
    public static function tooManyParticipants(int $max): string
    {
        return Translator::t('group with more than %d participants', [$max]);
    }
}
