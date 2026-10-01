<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use RuntimeException;

final class DeleteEventTest extends CalendarTestCase {
    protected function setUp(): void {
        parent::setUp();
        $this->store->addObject(1, 'd.ics', self::ics("UID:d\nDTSTART:20260312T090000Z\nDTEND:20260312T100000Z\nRRULE:FREQ=DAILY"));
    }

    private function delete(array $arguments): array {
        return $this->call('calendar_delete_event', $arguments + ['calendar' => self::PERSONAL, 'uid' => 'd', 'confirm' => true]);
    }

    public function testDeletesWholeSeriesIntoTheTrash(): void {
        $this->assertSame(
            ['uid' => 'd', 'calendar' => self::PERSONAL, 'deleted' => true, 'recoverable' => true, 'scheduling' => ['requested' => false, 'imipEnabled' => true, 'message' => 'nenhum convite foi agendado']],
            self::json($this->delete([])),
        );
        $this->assertSame([['delete', ['personal', 'd.ics', '"' . md5(self::ics("UID:d\nDTSTART:20260312T090000Z\nDTEND:20260312T100000Z\nRRULE:FREQ=DAILY")) . '"', false]]], $this->dav->calls);
        $this->assertArrayNotHasKey('d.ics', $this->store->objects[1], 'the core frees the original URI');
        $this->assertTrue($this->store->objects[1]['d-deleted.ics']['deleted'], 'the object must be in the trash under the renamed URI');
    }

    public function testDeleteSucceedsWhenTheCoreRenamesTheTrashedObject(): void {
        // Reproduces the production false error: the verification looked for "d.ics" and found nothing.
        $result = $this->delete([]);
        $this->assertArrayNotHasKey('isError', $result);
        $this->assertTrue(self::json($result)['deleted']);
    }

    public function testDeleteFailsWhenTheObjectIsNeitherLiveNorInTheTrash(): void {
        $this->dav->failureFor = [];
        $this->dav->statusFor['delete'] = 204; // answers without applying: object stays live
        $this->expectException(RuntimeException::class);
        $this->delete([]);
    }

    public function testBlockedWhenTrashRetentionIsDisabled(): void {
        $this->retention = '0';
        self::assertToolError($this->delete([]), 'lixeira do calendário está desativada');
        $this->assertNoWrites();
        $this->retention = '86400';
        self::json($this->delete([]));
    }

    public function testAclAndClassificationRefusals(): void {
        $this->store->addObject(4, 'x.ics', self::ics("UID:x\nDTSTART:20260312T090000Z\nDTEND:20260312T100000Z"));
        $this->store->addObject(3, 'c.ics', self::ics("UID:c\nCLASS:CONFIDENTIAL\nDTSTART:20260312T090000Z\nDTEND:20260312T100000Z"));
        self::assertToolError($this->delete(['calendar' => self::READONLY, 'uid' => 'x']), 'Sem permissão');
        self::assertToolError($this->delete(['calendar' => self::TEAM, 'uid' => 'c', 'confirm_shared' => true]), 'Sem permissão');
        self::assertToolError($this->delete(['calendar' => '/remote.php/dav/calendars/bob/personal/']), 'não encontrado');
        $this->assertNoWrites();
    }

    public function testSharedCalendarWithoutConfirmationDeletesNothing(): void {
        $payload = self::json($this->delete(['calendar' => self::TEAM, 'uid' => 'c']));

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

    public function testSharedCalendarWithConfirmationDeletesTheEvent(): void {
        $this->store->addObject(3, 'c.ics', self::ics("UID:c\nDTSTART:20260312T090000Z\nDTEND:20260312T100000Z"));

        self::json($this->delete(['calendar' => self::TEAM, 'uid' => 'c', 'confirm_shared' => true]));

        $this->assertSame('delete', $this->dav->calls[0][0]);
        $this->assertSame(['team_shared_by_bob', 'c.ics'], [$this->dav->calls[0][1][0], $this->dav->calls[0][1][1]]);
    }

    public function testDivergentEtagOrUnknownUidDeletesNothing(): void {
        self::assertToolError($this->delete(['etag' => 'stale']), 'etag divergente');
        self::assertToolError($this->delete(['uid' => 'missing']), 'não encontrado');
        $this->assertNoWrites();
    }

    public function testBackendFailurePropagates(): void {
        $this->dav->failureFor['delete'] = new RuntimeException('boom');
        $this->expectException(RuntimeException::class);
        $this->delete([]);
    }
}
