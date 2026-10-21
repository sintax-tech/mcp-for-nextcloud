<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Assignments;

use InvalidArgumentException;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Card;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\L10n\Translator;

class AssignedUsers {
    /**
     * Validates account IDs without echoing their values on failure.
     * @param mixed $value Requested account ID list.
     * @param string $field Argument the list came from, named in the failure.
     * @return list<string> Unique account IDs.
     * @throws InvalidArgumentException When the list or an account ID is malformed.
     */
    public static function assignees(mixed $value, string $field = 'assignees'): array {
        if (!is_array($value) || !array_is_list($value) || count($value) > 100) {
            throw new ArgumentValidationException('Invalid argument: ' . $field, $field, Translator::t('expected a list of at most 100 account IDs'));
        }
        foreach ($value as $uid) {
            if (!is_string($uid) || trim($uid) === '' || mb_strlen($uid) > 255) {
                throw new ArgumentValidationException(
                    'Invalid argument: ' . $field,
                    $field,
                    Translator::t('expected non-empty account IDs of at most 255 characters'),
                );
            }
        }
        return array_values(array_unique($value));
    }

    /**
     * UIDs of the people assigned to a card.
     *
     * Deck stores user, group and circle assignments in the same table (`Acl::PERMISSION_TYPE_*`);
     * only a user assignment names a person, so groups and circles are left out instead of being
     * answered as an "owner" that is not a user.
     *
     * @param Card $card Card whose assignments are already embedded, as the capability does.
     * @return list<string> UIDs, in the order Deck returned them.
     */
    public static function assignedUids(Card $card): array {
        $uids = [];
        foreach ($card->getAssignedUsers() ?? [] as $assignment) {
            if ($assignment->getType() !== Acl::PERMISSION_TYPE_USER) {
                continue;
            }
            $uids[] = (string) $assignment->getParticipant();
        }
        return $uids;
    }
}
