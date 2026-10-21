<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Selftest;

use OCA\Mcp\Modules\Calendar\Selftest\CalendarSelftestMessages;
use OCA\Mcp\Modules\Calendar\Selftest\CalendarSelftestReader;
use OCA\Mcp\Platform\Dav\CalDav\CollectionAccess as CalendarAccess;
use OCA\Mcp\Platform\Dav\CalDav\CalDavWriter as CalendarDav;
use OCA\Mcp\Platform\Dav\CalDav\ObjectStore as CalendarStore;
use OCP\IUser;
use OCP\IUserSession;
/** Cleans only reserved generated resources; every action retains independent failure reporting. */
final class SelftestCleanup
{
    public function __construct(
        private SelftestFixtures $fixtures,
        private CalendarDav $dispatcher,
        private \OCA\Mcp\Platform\Dav\CalDav\CalendarAdministration $administration,
        private CalendarAccess $access,
        private CalendarStore $store,
        private CalendarSelftestReader $reader,
        private IUserSession $session,
    )
    {
    }
    public function run(
        string $uid,
        ?IUser $user,
        array $owned,
        array $external,
        ?array $invitation,
        string $prefix,
        callable $report,
    ): bool
    {
        $clean = true;
        // Every cleanup action is attempted, even after a preceding cleanup failed.
        $attempt = function (string $resource, callable $action) use (&$clean, $report): void {
            try {
                $action();
            } catch (\Throwable $error) {
                $clean = false;
                $report(['step' => 'cleanup-resource', 'status' => 'FAIL', 'detail' => $resource . ': ' . SelftestAssertions::safeError($error)]);
            }
        };
        if ($user !== null) {
            $this->session->setVolatileActiveUser($user);
            if ($invitation !== null) {
                $attempt($invitation['uid'], fn() => $this->cleanupInvitation($user, $invitation));
            }
            foreach ($external as $entry) {
                $attempt(
                    $entry['calendar']->path . $entry['uid'],
                    function () use ($uid, $entry): void {
                        if ($this->store->objectByUid($entry['calendar']->id, $entry['uid'], 'VEVENT') !== null) {
                            $this->fixtures->delete($uid, $entry['calendar'], $entry['uid']);
                        }
                    }
                );
            }
            foreach ($owned as $uri => $calendar) {
                $attempt(
                    $this->fixtures->path($uid, $uri),
                    function () use ($uid, $uri, $calendar, $prefix): void {
                        SelftestAssertions::require(str_starts_with($uri, $prefix . '-'), CalendarSelftestMessages::assertion('reserved-uri'));
                        $current = null;
                        foreach ($this->access->visible($uid) as $visible) {
                            if ($visible->uri === $uri) {
                                $current = $visible;
                            }
                        }
                        if ($current === null) {
                            if ($calendar !== null) {
                                SelftestAssertions::require(($this->reader->calendarState($calendar)['deleted'] ?? false) === true, CalendarSelftestMessages::assertion('trashed-calendar'));
                            }
                            return;
                        }
                        SelftestAssertions::require($current->ownerId === $uid && ($calendar === null || $current->id === $calendar->id), CalendarSelftestMessages::assertion('cleanup-identity'));
                        $this->administration->deleteCalendar($uid, $uri);
                        $state = $this->reader->calendarState($current);
                        SelftestAssertions::require(($state['deleted'] ?? false) === true, CalendarSelftestMessages::assertion('trashed-calendar'));
                    }
                );
            }
        }
        return $clean;
    }
    private function cleanupInvitation(IUser $organizer, array $entry): void
    {
        $attendee = $entry['attendee'];
        $uid = $entry['uid'];
        try {
            if ($this->store->objectByUid($entry['calendar']->id, $uid, 'VEVENT') !== null) {
                $this->fixtures->delete(
                    $organizer->getUID(),
                    $entry['calendar'],
                    $uid,
                    true
                );
            }
        } finally {
            $this->session->setVolatileActiveUser($attendee);
            try {
                foreach ($this->fixtures->copies($attendee->getUID(), $uid) as $copy) {
                    $this->dispatcher->delete(
                        $attendee->getUID(),
                        $copy['calendar']->uri,
                        $copy['row']['uri'],
                        $copy['row']['etag'],
                        false
                    );
                }
                foreach ($this->fixtures->inbox($attendee->getUID(), $uid) as $item) {
                    // Re-read membership and ETag immediately before DELETE; no name from arguments.
                    foreach ($this->fixtures->inbox($attendee->getUID(), $uid) as $current) {
                        if ($current['uri'] === $item['uri'] && $current['etag'] === $item['etag']) {
                            $this->dispatcher->delete(
                                $attendee->getUID(),
                                'inbox',
                                $item['uri'],
                                $item['etag'],
                                false
                            );
                        }
                    }
                }
                SelftestAssertions::require($this->fixtures->copies($attendee->getUID(), $uid) === [] && $this->fixtures->inbox($attendee->getUID(), $uid) === [], CalendarSelftestMessages::assertion('participant-cleanup'));
            } finally {
                $this->session->setVolatileActiveUser($organizer);
            }
        }
    }
}
