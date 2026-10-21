<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Selftest;

use OCA\Mcp\Modules\Calendar\Selftest\CalendarSelftestMessages;
use OCA\Mcp\Platform\Dav\CalDav\CollectionAccess as CalendarAccess;
use OCA\Mcp\Platform\Dav\CalDav\CalDavWriter as CalendarDav;
use OCA\Mcp\Platform\Dav\CalDav\ObjectStore as CalendarStore;
use OCA\Mcp\Modules\Calendar\Editing\EventEditor as UpdateEvent;
use OCA\Mcp\Modules\Calendar\Relocation\EventMover as MoveEvent;
use OCA\Mcp\Modules\Calendar\Relocation\EventTransfer as TransferEvent;
use OCA\Mcp\Platform\Dav\CalDav\TrashPolicy;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserSession;
/** CLI-only native DAV scenarios; diagnostics never alter MCP exposure. */
final class CalendarSelftestRunner
{
    public function __construct(
        private CalendarDav $dispatcher,
        private \OCA\Mcp\Platform\Dav\CalDav\CalendarAdministration $administration,
        private \OCA\Mcp\Platform\Dav\CalDav\CalendarAclProbe $aclProbe,
        private UpdateEvent $updateEvent,
        private MoveEvent $moveEvent,
        private TransferEvent $transferEvent,
        private CalendarAccess $access,
        private CalendarStore $store,
        private ITimeFactory $time,
        private IUserSession $session,
        private TrashPolicy $trash,
        private SelftestFixtures $fixtures,
        private SelftestCleanup $cleanup,
    )
    {
    }
    public function run(
        string $uid,
        array $options,
        callable $report,
    ): bool
    {
        $previous = $this->session->getUser();
        $owned = [];
        $external = [];
        $invitation = null;
        $operations = [
            'create',
            'edit',
            'move',
            'delete',
        ];
        $passed = false;
        $step = 'preflight';
        try {
            $user = $this->fixtures->account($uid);
            SelftestAssertions::require($this->trash->recoverable(), CalendarSelftestMessages::TRASH_REQUIRED);
            $attendee = ($options['attendee-uid'] ?? '') === '' ? null : $this->fixtures->otherAccount($options['attendee-uid'], $uid);
            $probe = ($options['acl-probe-user'] ?? '') === '' ? null : $this->fixtures->otherAccount($options['acl-probe-user'], $uid);
            $this->session->setVolatileActiveUser($user);
            $shared = ($options['shared-calendar'] ?? '') === '' ? null : $this->access->resolveWritable($uid, $options['shared-calendar']);
            if ($shared !== null) {
                SelftestAssertions::require($shared->ownerId !== $uid, CalendarSelftestMessages::assertion('shared-owner'));
            }
            SelftestAssertions::ok($report, $step, $uid);
            $step = 'calendars';
            $prefix = 'mcp-selftest-' . $this->time->now()->format('YmdHis') . '-' . bin2hex(random_bytes(8));
            foreach (['a', 'b'] as $suffix) {
                $uri = $prefix . '-' . $suffix;
                foreach ($this->access->visible($uid) as $visible) {
                    SelftestAssertions::require($visible->uri !== $uri, CalendarSelftestMessages::assertion('fresh-uri'));
                }
                // Reserve only an absent, generated name. If MKCALENDAR mutates and then throws,
                // finally still examines this exact name rather than leaking a test calendar.
                $owned[$uri] = null;
                $result = $this->administration->mkcalendar($uid, $uri, CalendarSelftestMessages::EVENT);
                SelftestAssertions::require($result->status === 201, CalendarSelftestMessages::assertion('mkcalendar-status'));
                $owned[$uri] = $this->access->resolveWritable($uid, $this->fixtures->path($uid, $uri));
                SelftestAssertions::require($owned[$uri]->ownerId === $uid, CalendarSelftestMessages::assertion('created-owner'));
            }
            [$a, $b] = array_values($owned);
            SelftestAssertions::ok($report, $step, CalendarSelftestMessages::detail('calendars', $a->path, $b->path));
            $step = 'create';
            $token = $this->fixtures->token($a);
            $created = $this->fixtures->create($uid, $a);
            $row = $this->fixtures->live($a, $created['uid']);
            SelftestAssertions::require($row['etag'] === $created['etag'] && $this->fixtures->token($a) > $token, CalendarSelftestMessages::assertion('created-etag-sync'));
            SelftestAssertions::ok($report, $step, CalendarSelftestMessages::detail('create', $created['uid'], $this->fixtures->token($a)));
            $step = 'suppression-create';
            $suppressed = $this->fixtures->parse($row['data']);
            $suppressedUid = 'mcp-selftest-' . bin2hex(random_bytes(16));
            $suppressedUri = $suppressedUid . '.ics';
            $suppressed->VEVENT->UID = $suppressedUid;
            $suppressed->VEVENT->add('ORGANIZER', 'mailto:' . $user->getEMailAddress());
            $suppressed->VEVENT->add('ATTENDEE', 'mailto:mcp-selftest@example.invalid', ['RSVP' => 'TRUE']);
            $result = $this->dispatcher->put(
                $uid,
                $a->uri,
                $suppressedUri,
                $suppressed->serialize(),
                false
            );
            $suppressedRow = $this->fixtures->live($a, $suppressedUid);
            $this->fixtures->assertSuppressed($suppressedRow);
            SelftestAssertions::ok($report, $step, CalendarSelftestMessages::detail('suppression-create', $result->status));
            $step = 'suppression-update';
            $oldEtag = $suppressedRow['etag'];
            $oldSequence = (int) $this->fixtures->parse($suppressedRow['data'])->VEVENT->SEQUENCE->getValue();
            SelftestAssertions::tool($this->updateEvent->execute([
                'calendar' => $a->path,
                'uid' => $suppressedUid,
                'summary' => CalendarSelftestMessages::EVENT_EDITED,
                'send_invitations' => false,
            ], $uid));
            $edited = $this->fixtures->live($a, $suppressedUid);
            $event = $this->fixtures->parse($edited['data'])->VEVENT;
            SelftestAssertions::require((string) $event->SUMMARY === CalendarSelftestMessages::EVENT_EDITED && (int) (string) $event->SEQUENCE > $oldSequence, CalendarSelftestMessages::assertion('edited-summary-sequence'));
            $this->fixtures->assertSuppressed($edited);
            SelftestAssertions::ok($report, $step, CalendarSelftestMessages::detail('suppression-update'));
            $step = 'stale-tool';
            SelftestAssertions::expectRefusal(fn() => $this->updateEvent->execute([
                'calendar' => $a->path,
                'uid' => $suppressedUid,
                'summary' => CalendarSelftestMessages::EVENT,
                'etag' => $oldEtag,
            ], $uid));
            SelftestAssertions::require($this->fixtures->live($a, $suppressedUid) === $edited, CalendarSelftestMessages::assertion('tool-conflict-unchanged'));
            SelftestAssertions::ok($report, $step, CalendarSelftestMessages::detail('stale-tool'));
            $step = 'stale-dav';
            SelftestAssertions::expectRefusal(fn() => $this->dispatcher->update(
                $uid,
                $a->uri,
                $suppressedUri,
                $oldEtag,
                $suppressedRow['data'],
                false
            ), [412]);
            SelftestAssertions::require($this->fixtures->live($a, $suppressedUid) === $edited, CalendarSelftestMessages::assertion('dav-conflict-unchanged'));
            SelftestAssertions::ok($report, $step, CalendarSelftestMessages::detail('stale-dav'));
            $step = 'move';
            $tokens = [$this->fixtures->token($a), $this->fixtures->token($b)];
            SelftestAssertions::tool($this->moveEvent->execute(['calendar' => $a->path, 'uid' => $suppressedUid, 'targetCalendar' => $b->path], $uid));
            $moved = $this->fixtures->live($b, $suppressedUid);
            SelftestAssertions::require($this->store->objectByUid($a->id, $suppressedUid, 'VEVENT') === null && $this->fixtures->token($a) > $tokens[0] && $this->fixtures->token($b) > $tokens[1], CalendarSelftestMessages::assertion('move-presence-sync'));
            $this->fixtures->assertSuppressed($moved);
            SelftestAssertions::ok($report, $step, CalendarSelftestMessages::detail('move'));
            $step = 'overwrite';
            $collision = $this->fixtures->parse($row['data']);
            $collisionUid = 'mcp-selftest-' . bin2hex(random_bytes(16));
            $collision->VEVENT->UID = $collisionUid;
            $this->dispatcher->put(
                $uid,
                $b->uri,
                $row['uri'],
                $collision->serialize(),
                false
            );
            $targetBefore = $this->fixtures->live($b, $collisionUid);
            SelftestAssertions::expectRefusal(fn() => $this->dispatcher->move(
                $uid,
                $a->uri,
                $row['uri'],
                $b->uri
            ), [412]);
            SelftestAssertions::require($this->fixtures->live($a, $created['uid']) === $row && $this->fixtures->live($b, $collisionUid) === $targetBefore, CalendarSelftestMessages::assertion('overwrite-unchanged'));
            SelftestAssertions::ok($report, $step, CalendarSelftestMessages::detail('overwrite'));
            $step = 'delete';
            $this->fixtures->delete($uid, $b, $suppressedUid);
            $trashed = \OCA\Mcp\Platform\Dav\CalDav\TrashedObject::row($this->store, $b->id, $suppressedUri);
            SelftestAssertions::require($trashed !== null && $this->store->objectByUid($b->id, $suppressedUid, 'VEVENT') === null, CalendarSelftestMessages::assertion('deleted-object'));
            $this->fixtures->assertSuppressed($trashed);
            SelftestAssertions::ok($report, $step, CalendarSelftestMessages::detail('delete'));
            $step = 'invitations';
            if ($attendee === null) {
                SelftestAssertions::skip($report, $step);
            } else {
                $invite = $this->fixtures->create($uid, $a, [$attendee->getUID()]);
                // Known UID before any scheduling; journal survives a partial scheduler failure.
                $invitation = ['calendar' => $a, 'uid' => $invite['uid'], 'attendee' => $attendee];
                SelftestAssertions::tool($this->updateEvent->execute([
                    'calendar' => $a->path,
                    'uid' => $invite['uid'],
                    'summary' => CalendarSelftestMessages::EVENT_EDITED,
                    'send_invitations' => true,
                ], $uid));
                $written = $this->fixtures->parse($this->fixtures->live($a, $invite['uid'])['data']);
                $statuses = [];
                foreach ($written->VEVENT->select('ATTENDEE') as $guest) {
                    if (strcasecmp((string) $guest, 'mailto:' . $attendee->getEMailAddress()) === 0) {
                        $statuses[] = (string) ($guest['SCHEDULE-STATUS'] ?? '');
                    }
                }
                SelftestAssertions::require(count($statuses) === 1 && str_starts_with($statuses[0], '1.2'), CalendarSelftestMessages::assertion('internal-status'));
                SelftestAssertions::require($this->fixtures->copies($attendee->getUID(), $invite['uid']) !== [], CalendarSelftestMessages::assertion('internal-copy'));
                SelftestAssertions::require($this->fixtures->inbox($attendee->getUID(), $invite['uid'], 'REQUEST') !== [], CalendarSelftestMessages::assertion('internal-inbox'));
                SelftestAssertions::ok($report, $step, CalendarSelftestMessages::detail('invitations'));
            }
            $step = 'transfer';
            if ($shared === null) {
                SelftestAssertions::skip($report, $step);
            } else {
                $transfer = $this->fixtures->create($uid, $a);
                $external[] = ['calendar' => $shared, 'uid' => $transfer['uid']];
                SelftestAssertions::tool($this->transferEvent->execute([
                    'calendar' => $a->path,
                    'uid' => $transfer['uid'],
                    'targetCalendar' => $shared->path,
                    'confirm' => true,
                ], $uid));
                $this->fixtures->live($shared, $transfer['uid']);
                $this->fixtures->delete($uid, $shared, $transfer['uid']);
                SelftestAssertions::ok($report, $step, CalendarSelftestMessages::detail('transfer'));
            }
            $step = 'acl';
            if ($probe === null) {
                SelftestAssertions::skip($report, $step);
            } else {
                $probeUri = 'mcp-selftest-' . bin2hex(random_bytes(16)) . '.ics';
                $probeIcs = $this->fixtures->parse($row['data']);
                $probeIcs->VEVENT->UID = substr($probeUri, 0, -4);
                $this->session->setVolatileActiveUser($probe);
                try {
                    SelftestAssertions::expectRefusal(fn() => $this->aclProbe->probeWrite(
                        $probe->getUID(),
                        $uid,
                        $a->uri,
                        $probeUri,
                        $probeIcs->serialize()
                    ), [403, 404]);
                } finally {
                    $this->session->setVolatileActiveUser($user);
                }
                SelftestAssertions::require($this->store->object($a->id, $probeUri) === null, CalendarSelftestMessages::assertion('acl-unchanged'));
                SelftestAssertions::ok($report, $step, CalendarSelftestMessages::detail('acl'));
            }
            $passed = true;
        } catch (\Throwable $error) {
            $report(['step' => $step, 'status' => 'FAIL', 'detail' => SelftestAssertions::safeError($error)]);
        } finally {
            try {
                $clean = $this->cleanup->run(
                    $uid,
                    $user ?? null,
                    $owned,
                    $external,
                    $invitation,
                    $prefix ?? '',
                    $report
                );
            } finally {
                $this->session->setVolatileActiveUser($previous);
            }
            $report(['step' => 'cleanup', 'status' => $clean ? 'OK' : 'FAIL', 'detail' => $clean ? CalendarSelftestMessages::detail($owned === [] ? 'cleanup-empty' : 'cleanup') : CalendarSelftestMessages::FAILED]);
            $passed = $passed && $clean;
        }
        $report(['step' => 'result', 'status' => $passed ? 'OK' : 'FAIL', 'detail' => $passed ? CalendarSelftestMessages::PASSED : CalendarSelftestMessages::FAILED]);
        return $passed;
    }
}
