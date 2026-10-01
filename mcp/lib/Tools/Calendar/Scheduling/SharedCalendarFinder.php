<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar\Scheduling;

use OCA\Mcp\Tools\Calendar\Calendar;
use OCA\Mcp\Tools\Calendar\CalendarAccess;
use OCA\Mcp\Tools\Calendar\CalendarStore;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Finds the organizer's writable calendars that every attendee can already see, so an event placed there
 * shows up for all of them without sending invitations.
 */
final class SharedCalendarFinder {
    /** Principal prefix of Nextcloud users. */
    private const USER_PREFIX = 'principals/users/';
    /** Principal prefix of Nextcloud groups. */
    private const GROUP_PREFIX = 'principals/groups/';

    /**
     * @param CalendarAccess $access calendar visibility resolver
     * @param CalendarStore $store calendar storage port, for the shares
     * @param IUserManager $userManager user lookup
     * @param IGroupManager $groupManager group membership lookup
     */
    public function __construct(
        private CalendarAccess $access,
        private CalendarStore $store,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
    ) {}

    /**
     * Lists the organizer's writable VEVENT calendars that all attendees can see, as owner or through a
     * user or group share.
     *
     * @param string $organizerUid UID of the event creator
     * @param list<string> $attendeeUids UIDs of the participants
     * @return list<array{path:string, name:string, ownerId:string}> ordered by name; empty without attendees
     */
    public function candidates(string $organizerUid, array $attendeeUids): array {
        $attendeeUids = array_values(array_unique($attendeeUids));
        if ($attendeeUids === []) {
            return [];
        }
        $groupsByUser = [];
        foreach ($attendeeUids as $uid) {
            $user = $this->userManager->get($uid);
            if ($user === null) {
                return [];
            }
            $groupsByUser[$uid] = $this->groupManager->getUserGroupIds($user);
        }
        $out = [];
        foreach ($this->access->visible($organizerUid) as $calendar) {
            if (!$calendar->writable) {
                continue;
            }
            if ($this->seenByAll($calendar, $groupsByUser)) {
                $out[] = ['path' => $calendar->path, 'name' => $calendar->name, 'ownerId' => $calendar->ownerId];
            }
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * @param Calendar $calendar organizer's calendar
     * @param array<string, list<string>> $groupsByUser group ids of each attendee
     * @return bool whether every attendee owns the calendar or is covered by one of its shares
     */
    private function seenByAll(Calendar $calendar, array $groupsByUser): bool {
        $users = [];
        $groups = [];
        foreach ($this->store->sharesOf($calendar->id) as $share) {
            if (str_starts_with($share['principal'], self::USER_PREFIX)) {
                $users[substr($share['principal'], strlen(self::USER_PREFIX))] = true;
            } elseif (str_starts_with($share['principal'], self::GROUP_PREFIX)) {
                $groups[substr($share['principal'], strlen(self::GROUP_PREFIX))] = true;
            }
        }
        foreach ($groupsByUser as $uid => $userGroups) {
            $uid = (string)$uid;
            if ($uid === $calendar->ownerId || isset($users[$uid])) {
                continue;
            }
            $covered = false;
            foreach ($userGroups as $gid) {
                if (isset($groups[$gid])) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                return false;
            }
        }
        return true;
    }
}
