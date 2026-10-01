<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use RuntimeException;

final class MoveEventTest extends CalendarTestCase {
    private const SPRINT = '/remote.php/dav/calendars/alice/sprint_shared_by_bob/';

    protected function setUp(): void {
        parent::setUp();
        $this->store->addObject(1, 'm.ics', self::ics("UID:m\nDTSTART:20260312T090000Z\nDTEND:20260312T100000Z"));
        // A second calendar of bob's, so a move between two shared calendars can be exercised.
        $this->store->addCalendar(self::ALICE, 8, 'sprint_shared_by_bob', self::BOB, ['name' => 'Sprint (bob)']);
        $this->store->addObject(3, 't.ics', self::ics("UID:t\nDTSTART:20260312T090000Z\nDTEND:20260312T100000Z"));
    }

    private function move(array $arguments): array {
        return $this->call('calendar_move_event', $arguments + ['calendar' => self::PERSONAL, 'uid' => 'm', 'targetCalendar' => self::WORK]);
    }

    public function testMovesBetweenCalendarsOfTheSameOwner(): void {
        $this->assertSame(
            ['uid' => 'm', 'etag' => '"' . md5(self::ics("UID:m\nDTSTART:20260312T090000Z\nDTEND:20260312T100000Z")) . '"', 'from' => self::PERSONAL, 'to' => self::WORK, 'participantsNotified' => false, 'note' => 'Os participantes do evento não são avisados, como ao mover no app Calendar.'],
            self::json($this->move([])),
        );
        $this->assertSame([['move', ['personal', 'm.ics', 'work', '"' . md5(self::ics("UID:m\nDTSTART:20260312T090000Z\nDTEND:20260312T100000Z")) . '"']]], $this->dav->calls);
        $this->assertArrayNotHasKey('m.ics', $this->store->objects[1]);
        $this->assertArrayHasKey('m.ics', $this->store->objects[2]);
    }

    /** The move answers with the etag the event has now, so it can be changed or moved again without asking for a plan first. */
    public function testTheMoveReturnsTheEtagTheEventHasNow(): void {
        $payload = self::json($this->move([]));
        self::assertSame($this->store->objects[2]['m.ics']['etag'], $payload['etag']);
        $again = self::json($this->move(['calendar' => self::WORK, 'targetCalendar' => self::PERSONAL, 'etag' => $payload['etag']]));
        self::assertSame($this->store->objects[1]['m.ics']['etag'], $again['etag']);
    }

    public function testDifferentOwnersRequireTransfer(): void {
        self::assertToolError($this->move(['targetCalendar' => self::TEAM]), 'calendar_transfer_event');
        // Even fully confirmed, a cross-owner move stays the job of calendar_transfer_event.
        self::assertToolError($this->move(['targetCalendar' => self::TEAM, 'confirm_shared' => true]), 'calendar_transfer_event');
        $this->assertNoWrites();
    }

    public function testSharedCalendarsWithoutConfirmationMoveNothing(): void {
        $payload = self::json($this->move(['calendar' => self::TEAM, 'uid' => 't', 'targetCalendar' => self::SPRINT]));

        $this->assertTrue($payload['requiresConfirmation']);
        $this->assertSame(['shared', 'bob', 'Roberto Almeida', 'Equipe (bob)'], [
            $payload['scope'], $payload['owner'], $payload['ownerDisplayName'], $payload['resource'],
        ]);
        $this->assertSame(
            "O calendário 'Equipe (bob)' pertence a Roberto Almeida e é compartilhado com você. Alterações afetam outras pessoas."
            . ' Confirme com o usuário antes de continuar e repita a chamada com confirm_shared: true.',
            $payload['message'],
        );
        $this->assertNoWrites();
    }

    public function testSharedCalendarsWithConfirmationMoveTheEvent(): void {
        $this->assertSame(
            ['uid' => 't', 'etag' => '"' . md5(self::ics("UID:t\nDTSTART:20260312T090000Z\nDTEND:20260312T100000Z")) . '"', 'from' => self::TEAM, 'to' => self::SPRINT, 'participantsNotified' => false, 'note' => 'Os participantes do evento não são avisados, como ao mover no app Calendar.'],
            self::json($this->move(['calendar' => self::TEAM, 'uid' => 't', 'targetCalendar' => self::SPRINT, 'confirm_shared' => true])),
        );
        $this->assertSame([['move', ['team_shared_by_bob', 't.ics', 'sprint_shared_by_bob', '"' . md5(self::ics("UID:t\nDTSTART:20260312T090000Z\nDTEND:20260312T100000Z")) . '"']]], $this->dav->calls);
    }

    public function testAclRefusals(): void {
        self::assertToolError($this->move(['targetCalendar' => self::BIRTHDAYS]), 'Sem permissão');
        self::assertToolError($this->move(['targetCalendar' => self::PERSONAL]), 'mesmo calendário');
        self::assertToolError($this->move(['targetCalendar' => '/remote.php/dav/calendars/bob/work/']), 'não encontrado');
        self::assertToolError($this->move(['uid' => 'missing']), 'não encontrado');
        $this->assertNoWrites();
    }

    public function testUidConflictAndEtagNeverOverwrite(): void {
        $this->store->addObject(2, 'other.ics', self::ics("UID:m\nDTSTART:20260312T090000Z\nDTEND:20260312T100000Z"));
        self::assertToolError($this->move([]), 'mesmo UID');
        self::assertToolError($this->move(['etag' => 'stale']), 'etag divergente');
        $this->assertNoWrites();
    }

    public function testInvalidArgumentIsRejectedBeforeAnyWrite(): void {
        self::assertToolError($this->move(['calendar' => 'relative/path']), 'não encontrado');
        $this->assertNoWrites();
    }

    public function testBackendRefusalAndFailure(): void {
        // A MOVE the pipeline reports as done but that left the object in the source is never
        // reported as success: the re-read of both calendars is what decides.
        $this->dav->statusFor['move'] = 201;
        $this->expectException(RuntimeException::class);
        $this->move([]);
        $this->expectException(RuntimeException::class);
        $this->move([]);
    }
}
