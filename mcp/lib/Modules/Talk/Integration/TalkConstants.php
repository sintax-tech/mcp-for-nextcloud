<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Integration;

use OCA\Mcp\Modules\Talk\Execution\TalkUnavailableException;

class TalkConstants
{
    private const ATTENDEE_CLASS = 'OCA\\Talk\\Model\\Attendee';
    private const ROOM_CLASS = 'OCA\\Talk\\Room';
    private const WEBINARY_CLASS = 'OCA\\Talk\\Webinary';
    public function __construct(private TalkAvailability $availability) {}
    /**
     * Talk constants the permission checks depend on, read from the loaded classes instead of being copied here,
     * so a change of value upstream cannot silently loosen or break the ACL.
     *
     * @return array{chatPermission:int, lobbyIgnorePermission:int, readOnly:int, changelogType:int, lobbyNone:int, actorUsers:string, groupType:int}
     * @throws TalkUnavailableException When spreed is disabled for the user or a class is not loaded
     */
    public function forUser(string $userId): array
    {
        if (!$this->availability->isEnabledFor($userId)) {
            throw new TalkUnavailableException();
        }
        foreach ([self::ATTENDEE_CLASS, self::ROOM_CLASS, self::WEBINARY_CLASS] as $class) {
            if (!class_exists($class)) {
                throw new TalkUnavailableException();
            }
        }

        return [
            'chatPermission' => (int) constant(self::ATTENDEE_CLASS . '::PERMISSIONS_CHAT'),
            'lobbyIgnorePermission' => (int) constant(self::ATTENDEE_CLASS . '::PERMISSIONS_LOBBY_IGNORE'),
            'readOnly' => (int) constant(self::ROOM_CLASS . '::READ_ONLY'),
            'changelogType' => (int) constant(self::ROOM_CLASS . '::TYPE_CHANGELOG'),
            'lobbyNone' => (int) constant(self::WEBINARY_CLASS . '::LOBBY_NONE'),
            'actorUsers' => (string) constant(self::ATTENDEE_CLASS . '::ACTOR_USERS'),
            'groupType' => (int) constant(self::ROOM_CLASS . '::TYPE_GROUP'),
        ];
    }
}
