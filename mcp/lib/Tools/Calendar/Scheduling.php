<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Calendar;

use OCP\IAppConfig;
use Sabre\VObject\Component\VCalendar;

/**
 * Builds the `scheduling` block a write answers with.
 *
 * The block never claims that a message was delivered. It reports what was requested, whether the
 * server has e-mail invitations switched on, and the SCHEDULE-STATUS the Nextcloud scheduler left
 * on each guest. Sabre writes that status as a parameter of the ATTENDEE it hands back
 * (sabre/dav/lib/CalDAV/Schedule/Plugin.php:631-643), so it is read from the object re-read after
 * the write, never from what we sent.
 *
 * `dav/sendInvitations` decides whether the IMipPlugin is even loaded
 * (apps/dav/lib/CalDAV/EmbeddedCalDavServer.php:97-99); it is the same condition reported as
 * `imipEnabled`. With it off an internal guest can still be delivered to the local CalDAV inbox.
 */
final class Scheduling {
    /** App that owns the switch. */
    private const DAV_APP = 'dav';
    /** Switch key; anything but "yes" means the IMipPlugin is not loaded. */
    private const SEND_INVITATIONS_KEY = 'sendInvitations';
    /** Value of the switch that loads the IMipPlugin. */
    private const SEND_INVITATIONS_ON = 'yes';

    /**
     * @param IAppConfig $config Nextcloud configuration
     */
    public function __construct(private IAppConfig $config) {}

    /**
     * @return bool whether the server would try to send invitations by e-mail
     */
    public function imipEnabled(): bool {
        return $this->config->getValueString(self::DAV_APP, self::SEND_INVITATIONS_KEY, self::SEND_INVITATIONS_ON) === self::SEND_INVITATIONS_ON;
    }

    /**
     * @param bool $requested whether the caller asked for invitations to be scheduled
     * @param VCalendar|null $vcalendar object re-read after the write, or null when there is none
     * @param string $note sentence appended to the result, from CalendarMessages
     * @return array<string, mixed> the scheduling block of the tool result
     */
    public function report(bool $requested, ?VCalendar $vcalendar, string $note = ''): array {
        $block = ['requested' => $requested, 'imipEnabled' => $this->imipEnabled(), 'message' => $this->message($requested, $note)];
        $participants = $this->participants($vcalendar, $requested);
        if ($participants !== []) {
            $block['participants'] = $participants;
        }
        return $block;
    }

    /**
     * @param bool $requested whether the caller asked for invitations
     * @param string $note extra sentence from the caller
     * @return string the honest one-line summary
     */
    private function message(bool $requested, string $note): string {
        if (!$requested) {
            return CalendarMessages::schedulingSuppressed();
        }
        $base = CalendarMessages::schedulingHandedOver();
        if (!$this->imipEnabled()) {
            $base .= ' (' . CalendarMessages::imipDisabled() . ')';
        }
        return $note === '' ? $base : $base . $note;
    }

    /**
     * @param VCalendar|null $vcalendar object re-read after the write
     * @param bool $requested false suppresses historical scheduler status in this result
     * @return list<array{email:string, scheduleStatus:string|null, meaning:string}> one entry per guest
     */
    private function participants(?VCalendar $vcalendar, bool $requested): array {
        if ($vcalendar === null) {
            return [];
        }
        $event = $vcalendar->VEVENT;
        if ($event === null || !isset($event->ATTENDEE)) {
            return [];
        }
        $out = [];
        foreach ($event->select('ATTENDEE') as $attendee) {
            // A prior delivery status can survive a suppressed edit. It says nothing about
            // this request, so do not attribute it to a call that explicitly scheduled nothing.
            $status = $requested ? ($attendee['SCHEDULE-STATUS'] ?? null) : null;
            $code = $status === null ? null : $this->baseCode((string)$status);
            $out[] = [
                'email' => (string)$attendee,
                'scheduleStatus' => $status === null ? null : (string)$status,
                'meaning' => $code === null ? CalendarMessages::scheduleStatus('ausente') : CalendarMessages::scheduleStatus($code),
            ];
        }
        return $out;
    }

    /**
     * @param string $status full SCHEDULE-STATUS, e.g. "1.2;Message delivered locally"
     * @return string the leading code, e.g. "1.2"
     */
    private function baseCode(string $status): string {
        $code = strtok($status, ';');
        return $code === false ? $status : $code;
    }
}