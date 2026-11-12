<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Activity\Reading;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\References\ReadableCalendarEvent;
use OCA\Mcp\Access\ResourceScope\ScopedCalendarLookup;
use OCP\IURLGenerator;

/** Existing Calendar read ports revalidate current collection access and full-event classification, without new bridges. */
final class ActivityCalendarReferences {
	/**
	 * @param ActivityOriginAccess $access independent Calendar grant, app and Guests
	 * @param ScopedCalendarLookup $calendars existing actor-scoped collection port
	 * @param ReadableCalendarEvent $events existing current event projection and classification port
	 * @param IURLGenerator $urls canonical Calendar deep link
	 */
	public function __construct(private ActivityOriginAccess $access, private ScopedCalendarLookup $calendars, private ReadableCalendarEvent $events, private IURLGenerator $urls) {}
	/**
	 * @param ActivityReferenceView $view this call only
	 * @param int $id native calendar identity, never historical URI/name
	 * @param string $uid exact event UID from the known DAV producer
	 * @return array<string,mixed>|null authorized current event; no raw subject, link, old summary or parameters
	 */
	public function project(ActivityReferenceView $view, int $id, string $uid): ?array {
		$view->calendarAllowed ??= $this->access->allows($view->actor, 'calendar', 'calendar');
		if (!$view->calendarAllowed) return null;
		if ($view->calendars === null) {
			$view->calendars = [];
			foreach ($this->calendars->visible($view->actor, 'VEVENT') as $calendar) $view->calendars[$calendar->id][] = $calendar;
		}
		$candidates = $view->calendars[$id] ?? [];
		if ($candidates === []) return null;
		usort($candidates, static fn ($a, $b) => $a->path <=> $b->path);
		$read = $this->events->read($view->actor, $candidates[0]->path, $uid);
		if ($read->failure instanceof PolicyUnavailable) throw $read->failure;
		if ($read->item === null) return null;
		$item = $read->item;
		$dav = $this->urls->getWebroot() . $item['path'] . rawurlencode($item['uri']);
		return ['type' => 'calendar_event', 'uid' => $uid, 'calendar_id' => $id, 'name' => $item['title'],
			'url' => $this->urls->getAbsoluteURL($this->urls->linkToRoute('calendar.view.index') . 'edit/' . base64_encode($dav))];
	}
}
