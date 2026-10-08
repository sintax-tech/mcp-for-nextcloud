<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

use OCP\IUserManager;

/**
 * Turns the `attendees` argument into the accounts the Nextcloud scheduler can reach.
 *
 * Only internal UIDs are accepted, and each one is resolved to the e-mail of the account. A free
 * e-mail from outside is deliberately out of scope: an address the server cannot resolve would
 * produce an iTIP that the scheduler cannot deliver, and reporting success would be a lie.
 *
 * A UID that does not exist and an account without an e-mail produce the very same message, so a
 * caller cannot probe which accounts exist on the server. The message does name the UID, because
 * the caller supplied it and needs to know which entry to fix.
 */
final class AttendeeResolver {
    /** Largest accepted guest list. */
    public const MAX_ATTENDEES = 50;

    /**
     * @param IUserManager $users resolves the internal accounts
     */
    public function __construct(private IUserManager $users) {}

    /**
     * @param list<string> $uids internal account ids given by the caller
     * @param string $organizerUid UID of the acting user, who cannot also be a guest
     * @return list<array{uid:string, email:string, displayName:string}> one entry per accepted guest, in order
     * @throws CalendarArgumentException when the list is malformed, or a UID cannot be invited
     */
    public function resolve(array $uids, string $organizerUid): array {
        $uids = array_values($uids);
        if (count($uids) !== count(array_unique($uids))) {
            throw new CalendarArgumentException(CalendarMessages::attendeesInvalid());
        }
        foreach ($uids as $uid) {
            if ($uid === '' || $uid === $organizerUid) {
                throw new CalendarArgumentException(CalendarMessages::attendeeNotFound($uid));
            }
        }
        if ($uids !== [] && count($uids) > self::MAX_ATTENDEES) {
            throw new CalendarArgumentException(CalendarMessages::attendeesInvalid());
        }
        if ($uids !== [] && $this->email($organizerUid) === null) {
            throw new CalendarArgumentException(CalendarMessages::organizerWithoutEmail());
        }
        $resolved = [];
        foreach ($uids as $uid) {
            $email = $this->email($uid);
            if ($email === null) {
                throw new CalendarArgumentException(CalendarMessages::attendeeNotFound($uid));
            }
            $user = $this->users->get($uid);
            $resolved[] = ['uid' => $uid, 'email' => $email, 'displayName' => $user?->getDisplayName() ?: $uid];
        }
        return $resolved;
    }

    /**
     * @param string $uid UID of the acting user, who cannot also be a guest
     * @return array{email:string, displayName:string} the acting account, ready to be the ORGANIZER
     * @throws CalendarArgumentException when the account has no usable e-mail
     */
    public function organizer(string $uid): array {
        $email = $this->email($uid);
        if ($email === null) {
            throw new CalendarArgumentException(CalendarMessages::organizerWithoutEmail());
        }
        $user = $this->users->get($uid);
        return ['email' => $email, 'displayName' => $user?->getDisplayName() ?: $uid];
    }

    /**
     * @param string $uid account id
     * @return string|null the account e-mail, or null when it is unknown, disabled or has none
     */
    private function email(string $uid): ?string {
        $user = $this->users->get($uid);
        if ($user === null || !$user->isEnabled()) {
            return null;
        }
        $email = $user->getEMailAddress();
        return is_string($email) && $email !== '' ? $email : null;
    }
}