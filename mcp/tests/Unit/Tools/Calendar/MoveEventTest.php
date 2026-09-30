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
        $this->assertSame(['uid' => 'm', 'from' => self::PERSONAL, 'to' => self::WORK], self::json($this->move([])));
        $id = $this->store->objects[1]['m.ics']['id'];
        $this->assertSame([['move', [self::ALICE, $id, self::ALICE, 2, 'm.ics']]], $this->store->writes);
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
            ['uid' => 't', 'from' => self::TEAM, 'to' => self::SPRINT],
            self::json($this->move(['calendar' => self::TEAM, 'uid' => 't', 'targetCalendar' => self::SPRINT, 'confirm_shared' => true])),
        );
        $id = $this->store->objects[3]['t.ics']['id'];
        $this->assertSame([['move', [self::BOB, $id, self::BOB, 8, 't.ics']]], $this->store->writes);
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
        $this->store->moveResult = false;
        self::assertToolError($this->move([]), 'Não foi possível mover');
        $this->store->failure = new RuntimeException('boom');
        $this->expectException(RuntimeException::class);
        $this->move([]);
    }
}
