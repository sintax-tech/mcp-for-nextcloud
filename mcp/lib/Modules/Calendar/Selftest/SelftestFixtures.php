<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Selftest;

use OCA\Mcp\Modules\Calendar\Selftest\CalendarSelftestMessages;
use OCA\Mcp\Modules\Calendar\Selftest\CalendarSelftestReader;
use OCA\Mcp\Platform\Dav\Collections\Collection as Calendar;
use OCA\Mcp\Platform\Dav\CalDav\CollectionAccess as CalendarAccess;
use OCA\Mcp\Platform\Dav\CalDav\ObjectStore as CalendarStore;
use OCA\Mcp\Modules\Calendar\Creation\EventCreator as CreateEvent;
use OCA\Mcp\Modules\Calendar\Deletion\EventDeleter as DeleteEvent;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;
/** Generated diagnostic events and fresh read-back; no public tool routing. */
final class SelftestFixtures
{
    public function __construct(
        private CreateEvent $createEvent,
        private DeleteEvent $deleteEvent,
        private CalendarAccess $access,
        private CalendarStore $store,
        private ITimeFactory $time,
        private IUserManager $users,
        private IAppManager $apps,
        private CalendarSelftestReader $reader,
    )
    {
    }
    public function account(string $uid): IUser
    {
        $user = $this->users->get($uid);
        SelftestAssertions::require($user !== null && $user->isEnabled() && filter_var($user->getEMailAddress(), FILTER_VALIDATE_EMAIL) !== false && $this->apps->isEnabledForUser('calendar', $user), CalendarSelftestMessages::INVALID_USER);
        return $user;
    }
    public function otherAccount(string $uid, string $organizer): IUser
    {
        SelftestAssertions::require($uid !== $organizer, CalendarSelftestMessages::INVALID_ATTENDEE);
        return $this->account($uid);
    }
    public function create(
        string $uid,
        Calendar $calendar,
        array $guests = [],
    ): array
    {
        $start = $this->time->now()->modify('+1 day');
        return SelftestAssertions::tool(
            $this->createEvent->execute(
                [
                    'calendar' => $calendar->path,
                    'summary' => CalendarSelftestMessages::EVENT,
                    'start' => $start->format(DATE_ATOM),
                    'end' => $start->modify('+1 hour')->format(DATE_ATOM),
                    'attendees' => $guests,
                    'send_invitations' => false,
                ],
                $uid
            )
        );
    }
    public function delete(
        string $uid,
        Calendar $calendar,
        string $eventUid,
        bool $notify = false,
    ): void
    {
        SelftestAssertions::tool($this->deleteEvent->execute([
            'calendar' => $calendar->path,
            'uid' => $eventUid,
            'confirm' => true,
            'confirm_shared' => true,
            'send_invitations' => $notify,
        ], $uid));
    }
    public function live(Calendar $calendar, string $uid): array
    {
        $row = $this->store->objectByUid($calendar->id, $uid, 'VEVENT');
        SelftestAssertions::require($row !== null && !$row['deleted'], CalendarSelftestMessages::assertion('live-object'));
        return $row;
    }
    public function token(Calendar $calendar): int
    {
        $state = $this->reader->calendarState($calendar);
        SelftestAssertions::require($state !== null && ctype_digit($state['syncToken']) && !$state['deleted'], CalendarSelftestMessages::assertion('live-sync'));
        return (int) $state['syncToken'];
    }
    public function parse(string $data): VCalendar
    {
        $calendar = Reader::read($data);
        SelftestAssertions::require($calendar instanceof VCalendar, CalendarSelftestMessages::FAILED);
        return $calendar;
    }
    public function assertSuppressed(array $row): void
    {
        $guests = $this->parse($row['data'])->VEVENT->select('ATTENDEE');
        SelftestAssertions::require(count($guests) === 1, CalendarSelftestMessages::assertion('attendee-preserved'));
        foreach ($guests as $guest) {
            SelftestAssertions::require((string) $guest === 'mailto:mcp-selftest@example.invalid' && !isset($guest['SCHEDULE-STATUS']), CalendarSelftestMessages::assertion('suppressed'));
        }
    }
    public function copies(string $uid, string $eventUid): array
    {
        $copies = [];
        foreach ($this->access->visible($uid) as $calendar) {
            if ($calendar->ownerId === $uid && ($row = $this->store->objectByUid($calendar->id, $eventUid, 'VEVENT')) !== null) {
                $copies[] = ['calendar' => $calendar, 'row' => $row];
            }
        }
        return $copies;
    }
    public function inbox(
        string $uid,
        string $eventUid,
        ?string $method = null,
    ): array
    {
        $matches = [];
        foreach ($this->reader->schedulingObjects($uid) as $row) {
            $ical = $this->parse($row['data']);
            if ($ical->VEVENT !== null && (string) $ical->VEVENT->UID === $eventUid && ($method === null || (string) $ical->METHOD === $method)) {
                $matches[] = $row;
            }
        }
        return $matches;
    }
    public function path(string $uid, string $uri): string
    {
        return '/remote.php/dav/calendars/' . rawurlencode($uid) . '/' . rawurlencode($uri) . '/';
    }
}
