<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Selftest;

use OCA\Mcp\Modules\Calendar\Creation\CreateEventDraft;
use OCA\Mcp\Modules\Calendar\Deletion\DeleteEventDraft;
use OCA\Mcp\Modules\Calendar\Failures\CalendarException;
use OCA\Mcp\Platform\Dav\Calendar;
use OCA\Mcp\Modules\Calendar\Locating\CalendarStore;
use OCA\Mcp\Modules\Calendar\Writing\CalendarDav;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserSession;

/**
 * What the Calendar selftest writes: generated events created and deleted through the real drafts, the refusals it
 * expects, and the cleanup of an invitation on both sides. Every write names only identifiers the diagnostic generated.
 */
final class SelftestWrites {
	/**
	 * @param CreateEventDraft $createEvent the real create path
	 * @param DeleteEventDraft $deleteEvent the real delete path
	 * @param ITimeFactory $time clock of the generated events
	 * @param IUserSession $session session switched to the participant during cleanup
	 * @param CalendarDav $dispatcher the CalDAV pipeline the cleanup deletes through
	 * @param CalendarStore $store the live calendar objects
	 * @param SelftestEvidence $evidence the readback that proves each effect
	 */
	public function __construct(
		private CreateEventDraft $createEvent,
		private DeleteEventDraft $deleteEvent,
		private ITimeFactory $time,
		private IUserSession $session,
		private CalendarDav $dispatcher,
		private CalendarStore $store,
		private SelftestEvidence $evidence,
	) {
	}

	/**
	 * @param array<string, mixed> $result raw MCP result from the handler
	 * @return array<string, mixed> decoded result from a successful real handler
	 * @throws \RuntimeException when the handler refused or only asked for confirmation
	 */
	public function tool(array $result): array {
		$this->evidence->require(!($result['isError'] ?? false), CalendarSelftestMessages::FAILED);
		$value = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
		$this->evidence->require(is_array($value) && !($value['requiresConfirmation'] ?? false), CalendarSelftestMessages::FAILED);
		return $value;
	}

	/**
	 * @param string $uid internal user id used to run the handler
	 * @param Calendar $calendar calendar that will hold the generated event
	 * @param list<string> $guests attendee account ids
	 * @return array<string, mixed> result of the create handler with a generated UUID
	 */
	public function create(string $uid, Calendar $calendar, array $guests = []): array {
		$start = $this->time->now()->modify('+1 day');
		return $this->tool($this->createEvent->execute(['calendar' => $calendar->path, 'summary' => CalendarSelftestMessages::EVENT,
			'start' => $start->format(DATE_ATOM), 'end' => $start->modify('+1 hour')->format(DATE_ATOM), 'attendees' => $guests, 'send_invitations' => false], $uid));
	}

	/**
	 * Deletes a known generated UID through the real handler.
	 *
	 * @param string $uid internal user id used to run the handler
	 * @param Calendar $calendar calendar that holds the event
	 * @param string $eventUid generated event UID
	 * @param bool $notify whether the deletion schedules a CANCEL
	 * @return void the event went to the trash bin
	 */
	public function delete(string $uid, Calendar $calendar, string $eventUid, bool $notify = false): void {
		$this->tool($this->deleteEvent->execute(['calendar' => $calendar->path, 'uid' => $eventUid, 'confirm' => true, 'confirm_shared' => true, 'send_invitations' => $notify], $uid));
	}

	/**
	 * @param callable(): mixed $action operation expected to be refused
	 * @param list<int> $httpCodes accepted HTTP status codes, when a DAV precondition is under test
	 * @return void expects a mapped refusal; only explicit HTTP codes prove DAV preconditions
	 * @throws \RuntimeException when the action was not refused, or with another status
	 */
	public function expectRefusal(callable $action, array $httpCodes = []): void {
		try { $action(); } catch (CalendarException $error) {
			$this->evidence->require($httpCodes === [] || in_array($error->getCode(), $httpCodes, true), CalendarSelftestMessages::assertion('refusal-status'));
			return;
		}
		throw new \RuntimeException(CalendarSelftestMessages::assertion('refusal'));
	}

	/**
	 * @param IUser $organizer account that owns the event
	 * @param array{attendee:IUser, uid:string, calendar:Calendar} $entry generated invitation cleanup data
	 * @return void cancels as organizer then removes only matching participant copies/inbox items
	 */
	public function cleanupInvitation(IUser $organizer, array $entry): void {
		$attendee = $entry['attendee'];
		$uid = $entry['uid'];
		try {
			if ($this->store->objectByUid($entry['calendar']->id, $uid, 'VEVENT') !== null) { $this->delete($organizer->getUID(), $entry['calendar'], $uid, true); }
		} finally {
			$this->session->setVolatileActiveUser($attendee);
			try {
				foreach ($this->evidence->copies($attendee->getUID(), $uid) as $copy) {
					$this->dispatcher->delete($attendee->getUID(), $copy['calendar']->uri, $copy['row']['uri'], $copy['row']['etag'], false);
				}
				foreach ($this->evidence->inbox($attendee->getUID(), $uid) as $item) {
					// Re-read membership and ETag immediately before DELETE; no name from arguments.
					foreach ($this->evidence->inbox($attendee->getUID(), $uid) as $current) {
						if ($current['uri'] === $item['uri'] && $current['etag'] === $item['etag']) {
							$this->dispatcher->delete($attendee->getUID(), 'inbox', $item['uri'], $item['etag'], false);
						}
					}
				}
				$this->evidence->require($this->evidence->copies($attendee->getUID(), $uid) === [] && $this->evidence->inbox($attendee->getUID(), $uid) === [], CalendarSelftestMessages::assertion('participant-cleanup'));
			} finally { $this->session->setVolatileActiveUser($organizer); }
		}
	}
}
