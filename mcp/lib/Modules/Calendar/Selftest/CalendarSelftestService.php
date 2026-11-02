<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Selftest;

use OCA\Mcp\Modules\Calendar\Locating\CalendarAccess;
use OCA\Mcp\Modules\Calendar\Failures\CalendarArgumentException;
use OCA\Mcp\Modules\Calendar\Writing\CalendarDav;
use OCA\Mcp\Modules\Calendar\Failures\CalendarException;
use OCA\Mcp\Modules\Calendar\Locating\CalendarStore;
use OCA\Mcp\Modules\Calendar\Editing\UpdateEventDraft;
use OCA\Mcp\Modules\Calendar\Moving\MoveEventDraft;
use OCA\Mcp\Modules\Calendar\Moving\TransferEventDraft;
use OCA\Mcp\Modules\Calendar\Writing\TrashPolicy;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserSession;

/** Optional diagnostic: runs real handlers and DAV and reports; it records nothing and changes no exposure. */
final class CalendarSelftestService {
	/**
	 * All collaborators are ports or ordinary tool handlers; nothing writes via the backend.
	 *
	 * @param CalendarDav $dispatcher the CalDAV pipeline the diagnostic writes and probes through
	 * @param UpdateEventDraft $updateEvent the real update path
	 * @param MoveEventDraft $moveEvent the real move path
	 * @param TransferEventDraft $transferEvent the real transfer path
	 * @param CalendarAccess $access the calendars the organizer sees
	 * @param CalendarStore $store the live calendar objects
	 * @param ITimeFactory $time clock of the generated names
	 * @param IUserSession $session session switched to each account the diagnostic acts as
	 * @param TrashPolicy $trash whether a deleted object stays recoverable
	 * @param CalendarSelftestReader $reader backend state the tool results do not expose
	 * @param SelftestEvidence $evidence the readback that proves each effect
	 * @param SelftestWrites $writes the writes through the real drafts and their cleanup
	 */
	public function __construct(
		private CalendarDav $dispatcher,
		private UpdateEventDraft $updateEvent,
		private MoveEventDraft $moveEvent,
		private TransferEventDraft $transferEvent,
		private CalendarAccess $access,
		private CalendarStore $store,
		private ITimeFactory $time,
		private IUserSession $session,
		private TrashPolicy $trash,
		private CalendarSelftestReader $reader,
		private SelftestEvidence $evidence,
		private SelftestWrites $writes,
	) {}

	/**
	 * @param string $uid internal organizer account; never obtained from MCP arguments
	 * @param array{attendee-uid?:string, shared-calendar?:string, acl-probe-user?:string} $options CLI options
	 * @param callable(array{step:string,status:string,detail:string}):void $report safe per-step report
	 * @return bool whether every requested check and cleanup succeeded
	 */
	public function run(string $uid, array $options, callable $report): bool {
		$previous = $this->session->getUser();
		$owned = [];
		$external = [];
		$invitation = null;
		$operations = ['create', 'edit', 'move', 'delete'];
		$passed = false;
		$step = 'preflight';
		try {
			$user = $this->evidence->account($uid);
			$this->evidence->require($this->trash->recoverable(), CalendarSelftestMessages::TRASH_REQUIRED);
			$attendee = ($options['attendee-uid'] ?? '') === '' ? null : $this->evidence->otherAccount($options['attendee-uid'], $uid);
			$probe = ($options['acl-probe-user'] ?? '') === '' ? null : $this->evidence->otherAccount($options['acl-probe-user'], $uid);
			$this->session->setVolatileActiveUser($user);
			$shared = ($options['shared-calendar'] ?? '') === '' ? null : $this->access->resolveWritable($uid, $options['shared-calendar']);
			if ($shared !== null) {
				$this->evidence->require($shared->ownerId !== $uid, CalendarSelftestMessages::assertion('shared-owner'));
			}
			$this->ok($report, $step, $uid);

			$step = 'calendars';
			$prefix = 'mcp-selftest-' . $this->time->now()->format('YmdHis') . '-' . bin2hex(random_bytes(8));
			foreach (['a', 'b'] as $suffix) {
				$uri = $prefix . '-' . $suffix;
				foreach ($this->access->visible($uid) as $visible) {
					$this->evidence->require($visible->uri !== $uri, CalendarSelftestMessages::assertion('fresh-uri'));
				}
				// Reserve only an absent, generated name. If MKCALENDAR mutates and then throws,
				// finally still examines this exact name rather than leaking a test calendar.
				$owned[$uri] = null;
				$result = $this->dispatcher->mkcalendar($uid, $uri, CalendarSelftestMessages::EVENT);
				$this->evidence->require($result->status === 201, CalendarSelftestMessages::assertion('mkcalendar-status'));
				$owned[$uri] = $this->access->resolveWritable($uid, $this->path($uid, $uri));
				$this->evidence->require($owned[$uri]->ownerId === $uid, CalendarSelftestMessages::assertion('created-owner'));
			}
			[$a, $b] = array_values($owned);
			$this->ok($report, $step, CalendarSelftestMessages::detail('calendars', $a->path, $b->path));

			$step = 'create';
			$token = $this->evidence->token($a);
			$created = $this->writes->create($uid, $a);
			$row = $this->evidence->live($a, $created['uid']);
			$this->evidence->require($row['etag'] === $created['etag'] && $this->evidence->token($a) > $token, CalendarSelftestMessages::assertion('created-etag-sync'));
			$this->ok($report, $step, CalendarSelftestMessages::detail('create', $created['uid'], $this->evidence->token($a)));

			$step = 'suppression-create';
			$suppressed = $this->evidence->parse($row['data']);
			$suppressedUid = 'mcp-selftest-' . bin2hex(random_bytes(16));
			$suppressedUri = $suppressedUid . '.ics';
			$suppressed->VEVENT->UID = $suppressedUid;
			$suppressed->VEVENT->add('ORGANIZER', 'mailto:' . $user->getEMailAddress());
			$suppressed->VEVENT->add('ATTENDEE', 'mailto:mcp-selftest@example.invalid', ['RSVP' => 'TRUE']);
			$result = $this->dispatcher->put($uid, $a->uri, $suppressedUri, $suppressed->serialize(), false);
			$suppressedRow = $this->evidence->live($a, $suppressedUid);
			$this->evidence->assertSuppressed($suppressedRow);
			$this->ok($report, $step, CalendarSelftestMessages::detail('suppression-create', $result->status));

			$step = 'suppression-update';
			$oldEtag = $suppressedRow['etag'];
			$oldSequence = (int)$this->evidence->parse($suppressedRow['data'])->VEVENT->SEQUENCE->getValue();
			$this->writes->tool($this->updateEvent->execute(['calendar' => $a->path, 'uid' => $suppressedUid, 'summary' => CalendarSelftestMessages::EVENT_EDITED, 'send_invitations' => false], $uid));
			$edited = $this->evidence->live($a, $suppressedUid);
			$event = $this->evidence->parse($edited['data'])->VEVENT;
			$this->evidence->require((string)$event->SUMMARY === CalendarSelftestMessages::EVENT_EDITED && (int)(string)$event->SEQUENCE > $oldSequence, CalendarSelftestMessages::assertion('edited-summary-sequence'));
			$this->evidence->assertSuppressed($edited);
			$this->ok($report, $step, CalendarSelftestMessages::detail('suppression-update'));

			$step = 'stale-tool';
			$this->writes->expectRefusal(fn () => $this->updateEvent->execute(['calendar' => $a->path, 'uid' => $suppressedUid, 'summary' => CalendarSelftestMessages::EVENT, 'etag' => $oldEtag], $uid));
			$this->evidence->require($this->evidence->live($a, $suppressedUid) === $edited, CalendarSelftestMessages::assertion('tool-conflict-unchanged'));
			$this->ok($report, $step, CalendarSelftestMessages::detail('stale-tool'));

			$step = 'stale-dav';
			$this->writes->expectRefusal(fn () => $this->dispatcher->update($uid, $a->uri, $suppressedUri, $oldEtag, $suppressedRow['data'], false), [412]);
			$this->evidence->require($this->evidence->live($a, $suppressedUid) === $edited, CalendarSelftestMessages::assertion('dav-conflict-unchanged'));
			$this->ok($report, $step, CalendarSelftestMessages::detail('stale-dav'));

			$step = 'move';
			$tokens = [$this->evidence->token($a), $this->evidence->token($b)];
			$this->writes->tool($this->moveEvent->execute(['calendar' => $a->path, 'uid' => $suppressedUid, 'targetCalendar' => $b->path], $uid));
			$moved = $this->evidence->live($b, $suppressedUid);
			$this->evidence->require($this->store->objectByUid($a->id, $suppressedUid, 'VEVENT') === null && $this->evidence->token($a) > $tokens[0] && $this->evidence->token($b) > $tokens[1], CalendarSelftestMessages::assertion('move-presence-sync'));
			$this->evidence->assertSuppressed($moved);
			$this->ok($report, $step, CalendarSelftestMessages::detail('move'));

			$step = 'overwrite';
			$collision = $this->evidence->parse($row['data']);
			$collisionUid = 'mcp-selftest-' . bin2hex(random_bytes(16));
			$collision->VEVENT->UID = $collisionUid;
			$this->dispatcher->put($uid, $b->uri, $row['uri'], $collision->serialize(), false);
			$targetBefore = $this->evidence->live($b, $collisionUid);
			$this->writes->expectRefusal(fn () => $this->dispatcher->move($uid, $a->uri, $row['uri'], $b->uri), [412]);
			$this->evidence->require($this->evidence->live($a, $created['uid']) === $row && $this->evidence->live($b, $collisionUid) === $targetBefore, CalendarSelftestMessages::assertion('overwrite-unchanged'));
			$this->ok($report, $step, CalendarSelftestMessages::detail('overwrite'));

			$step = 'delete';
			$this->writes->delete($uid, $b, $suppressedUid);
			$trashed = \OCA\Mcp\Modules\Calendar\Writing\TrashedObject::row($this->store, $b->id, $suppressedUri);
			$this->evidence->require($trashed !== null && $this->store->objectByUid($b->id, $suppressedUid, 'VEVENT') === null, CalendarSelftestMessages::assertion('deleted-object'));
			$this->evidence->assertSuppressed($trashed);
			$this->ok($report, $step, CalendarSelftestMessages::detail('delete'));

			$step = 'invitations';
			if ($attendee === null) {
				$this->skip($report, $step);
			} else {
				$invite = $this->writes->create($uid, $a, [$attendee->getUID()]);
				// Known UID before any scheduling; journal survives a partial scheduler failure.
				$invitation = ['calendar' => $a, 'uid' => $invite['uid'], 'attendee' => $attendee];
				$this->writes->tool($this->updateEvent->execute(['calendar' => $a->path, 'uid' => $invite['uid'], 'summary' => CalendarSelftestMessages::EVENT_EDITED, 'send_invitations' => true], $uid));
				$written = $this->evidence->parse($this->evidence->live($a, $invite['uid'])['data']);
				$statuses = [];
				foreach ($written->VEVENT->select('ATTENDEE') as $guest) {
					if (strcasecmp((string)$guest, 'mailto:' . $attendee->getEMailAddress()) === 0) { $statuses[] = (string)($guest['SCHEDULE-STATUS'] ?? ''); }
				}
				$this->evidence->require(count($statuses) === 1 && str_starts_with($statuses[0], '1.2'), CalendarSelftestMessages::assertion('internal-status'));
				$this->evidence->require($this->evidence->copies($attendee->getUID(), $invite['uid']) !== [], CalendarSelftestMessages::assertion('internal-copy'));
				$this->evidence->require($this->evidence->inbox($attendee->getUID(), $invite['uid'], 'REQUEST') !== [], CalendarSelftestMessages::assertion('internal-inbox'));
				$this->ok($report, $step, CalendarSelftestMessages::detail('invitations'));
			}

			$step = 'transfer';
			if ($shared === null) {
				$this->skip($report, $step);
			} else {
				$transfer = $this->writes->create($uid, $a);
				$external[] = ['calendar' => $shared, 'uid' => $transfer['uid']];
				$this->writes->tool($this->transferEvent->execute(['calendar' => $a->path, 'uid' => $transfer['uid'], 'targetCalendar' => $shared->path, 'confirm' => true], $uid));
				$this->evidence->live($shared, $transfer['uid']);
				$this->writes->delete($uid, $shared, $transfer['uid']);
				$this->ok($report, $step, CalendarSelftestMessages::detail('transfer'));
			}

			$step = 'acl';
			if ($probe === null) {
				$this->skip($report, $step);
			} else {
				$probeUri = 'mcp-selftest-' . bin2hex(random_bytes(16)) . '.ics';
				$probeIcs = $this->evidence->parse($row['data']);
				$probeIcs->VEVENT->UID = substr($probeUri, 0, -4);
				$this->session->setVolatileActiveUser($probe);
				try {
					$this->writes->expectRefusal(fn () => $this->dispatcher->probeWrite($probe->getUID(), $uid, $a->uri, $probeUri, $probeIcs->serialize()), [403, 404]);
				} finally {
					$this->session->setVolatileActiveUser($user);
				}
				$this->evidence->require($this->store->object($a->id, $probeUri) === null, CalendarSelftestMessages::assertion('acl-unchanged'));
				$this->ok($report, $step, CalendarSelftestMessages::detail('acl'));
			}
			$passed = true;
		} catch (\Throwable $error) {
			$report(['step' => $step, 'status' => 'FAIL', 'detail' => $this->safeError($error)]);
		} finally {
			$clean = true;
			// Every cleanup action is attempted, even after a preceding cleanup failed.
			$attempt = function (string $resource, callable $action) use (&$clean, $report): void {
				try { $action(); } catch (\Throwable $error) {
					$clean = false;
					$report(['step' => 'cleanup-resource', 'status' => 'FAIL', 'detail' => $resource . ': ' . $this->safeError($error)]);
				}
			};
			if (isset($user)) {
				$this->session->setVolatileActiveUser($user);
				if ($invitation !== null) {
					$attempt($invitation['uid'], fn () => $this->writes->cleanupInvitation($user, $invitation));
				}
				foreach ($external as $entry) {
					$attempt($entry['calendar']->path . $entry['uid'], function () use ($uid, $entry): void {
						if ($this->store->objectByUid($entry['calendar']->id, $entry['uid'], 'VEVENT') !== null) { $this->writes->delete($uid, $entry['calendar'], $entry['uid']); }
					});
				}
				foreach ($owned as $uri => $calendar) {
					$attempt($this->path($uid, $uri), function () use ($uid, $uri, $calendar, $prefix): void {
						$this->evidence->require(str_starts_with($uri, $prefix . '-'), CalendarSelftestMessages::assertion('reserved-uri'));
						$current = null;
						foreach ($this->access->visible($uid) as $visible) { if ($visible->uri === $uri) { $current = $visible; } }
						if ($current === null) {
							if ($calendar !== null) { $this->evidence->require(($this->reader->calendarState($calendar)['deleted'] ?? false) === true, CalendarSelftestMessages::assertion('trashed-calendar')); }
							return;
						}
						$this->evidence->require($current->ownerId === $uid && ($calendar === null || $current->id === $calendar->id), CalendarSelftestMessages::assertion('cleanup-identity'));
						$this->dispatcher->deleteCalendar($uid, $uri);
						$state = $this->reader->calendarState($current);
						$this->evidence->require(($state['deleted'] ?? false) === true, CalendarSelftestMessages::assertion('trashed-calendar'));
					});
				}
			}
			$this->session->setVolatileActiveUser($previous);
			$report(['step' => 'cleanup', 'status' => $clean ? 'OK' : 'FAIL', 'detail' => $clean ? CalendarSelftestMessages::detail($owned === [] ? 'cleanup-empty' : 'cleanup') : CalendarSelftestMessages::FAILED]);
			$passed = $passed && $clean;
		}
		$report(['step' => 'result', 'status' => $passed ? 'OK' : 'FAIL', 'detail' => $passed ? CalendarSelftestMessages::PASSED : CalendarSelftestMessages::FAILED]);
		return $passed;
	}

	/**
	 * @param string $uid organizer account
	 * @param string $uri generated calendar URI
	 * @return string path made from generated collection identifiers
	 */
	private function path(string $uid, string $uri): string {
		return '/remote.php/dav/calendars/' . rawurlencode($uid) . '/' . rawurlencode($uri) . '/';
	}

	/**
	 * @param \Throwable $error failure of a step or of a cleanup action
	 * @return string no raw backend exception, ICS or XML is printed
	 */
	private function safeError(\Throwable $error): string {
		if ($error instanceof CalendarException || $error instanceof CalendarArgumentException) { return $error->getMessage(); }
		if (str_starts_with($error->getMessage(), CalendarSelftestMessages::ASSERTION_PREFIX) || in_array($error->getMessage(), [CalendarSelftestMessages::TRASH_REQUIRED, CalendarSelftestMessages::INVALID_USER, CalendarSelftestMessages::INVALID_ATTENDEE], true)) { return $error->getMessage(); }
		return CalendarSelftestMessages::FAILED . ' (' . $error::class . ')';
	}

	/**
	 * @param callable(array{step:string, status:string, detail:string}):void $report safe report callback
	 * @param string $step diagnostic stage name
	 * @param string $detail safe evidence for the stage
	 * @return void emits a successful stage
	 */
	private function ok(callable $report, string $step, string $detail): void {
		$report(['step' => $step, 'status' => 'OK', 'detail' => $detail]);
	}

	/**
	 * @param callable(array{step:string, status:string, detail:string}):void $report safe report callback
	 * @param string $step diagnostic stage name
	 * @return void emits an optional stage without claiming proof
	 */
	private function skip(callable $report, string $step): void {
		$report(['step' => $step, 'status' => 'NOT TESTED', 'detail' => CalendarSelftestMessages::OPTIONAL]);
	}
}
