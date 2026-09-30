<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use RuntimeException;

final class TransferEventTest extends CalendarTestCase {
    protected function setUp(): void {
        parent::setUp();
        $this->store->addObject(1, 't.ics', self::ics("UID:t\nCLASS:PRIVATE\nDTSTART:20260312T090000Z\nDTEND:20260312T100000Z"));
    }

    private function transfer(array $arguments): array {
        return $this->call('calendar_transfer_event', $arguments + ['calendar' => self::PERSONAL, 'uid' => 't', 'targetCalendar' => self::TEAM, 'confirm' => true]);
    }

    public function testTransfersToAnotherOwnersWritableCalendar(): void {
        $this->assertSame(
            ['uid' => 't', 'from' => self::PERSONAL, 'to' => self::TEAM, 'participantsNotified' => false, 'note' => 'Os participantes do evento não são avisados, como ao mover no app Calendar.'],
            self::json($this->transfer([])),
        );
        $this->assertSame([['move', ['personal', 't.ics', 'team_shared_by_bob', '"' . md5(self::ics("UID:t\nCLASS:PRIVATE\nDTSTART:20260312T090000Z\nDTEND:20260312T100000Z")) . '"']]], $this->dav->calls);
    }

    public function testReadOnlyTargetAndSameOwnerAreRefused(): void {
        self::assertToolError($this->transfer(['targetCalendar' => self::READONLY]), 'Sem permissão');
        self::assertToolError($this->transfer(['targetCalendar' => self::WORK]), 'calendar_move_event');
        $this->assertNoWrites();
    }

    public function testProtectedEventsOfTheSourceOwnerCannotBeTaken(): void {
        $this->store->addObject(3, 'p.ics', self::ics("UID:p\nCLASS:PRIVATE\nDTSTART:20260312T090000Z\nDTEND:20260312T100000Z"));
        $this->store->addObject(3, 'c.ics', self::ics("UID:c\nCLASS:CONFIDENTIAL\nDTSTART:20260312T090000Z\nDTEND:20260312T100000Z"));
        $args = ['calendar' => self::TEAM, 'targetCalendar' => self::PERSONAL];
        self::assertToolError($this->transfer(['uid' => 'p'] + $args), 'não encontrado');
        self::assertToolError($this->transfer(['uid' => 'c'] + $args), 'Sem permissão');
        $this->assertNoWrites();
    }

    public function testTargetConflictNeverOverwrites(): void {
        $this->store->addObject(3, 't.ics', self::ics("UID:other\nDTSTART:20260312T090000Z\nDTEND:20260312T100000Z"));
        self::assertToolError($this->transfer([]), 'mesmo UID');
        $this->assertNoWrites();
    }

    public function testInvalidTargetPathIsNotFound(): void {
        self::assertToolError($this->transfer(['targetCalendar' => '/remote.php/dav/calendars/bob/team/']), 'não encontrado');
        $this->assertNoWrites();
    }

    public function testATransferCarryingGuestsIsRefusedBeforeAnyDispatch(): void {
        $this->store->addObject(1, 'w.ics', self::ics(
            "UID:w\nSUMMARY:Comiliao\nDTSTART:20260312T090000Z\nDTEND:20260312T100000Z\n"
            . "ORGANIZER:mailto:alice@example.invalid\nATTENDEE;CN=Bob:mailto:bob@example.invalid"
        ));

        self::assertToolError($this->transfer(['uid' => 'w']), 'organizador continua sendo você');
        $this->assertNoWrites();
    }

    public function testATransferWithoutGuestsGoesThrough(): void {
        $this->store->addObject(1, 'n.ics', self::ics("UID:n\nDTSTART:20260312T090000Z\nDTEND:20260312T100000Z"));

        self::json($this->transfer(['uid' => 'n']));

        $this->assertSame('move', $this->dav->calls[0][0]);
    }

    public function testBackendFailurePropagates(): void {
        $this->dav->failureFor['move'] = new RuntimeException('boom');
        $this->expectException(RuntimeException::class);
        $this->transfer([]);
    }
}
