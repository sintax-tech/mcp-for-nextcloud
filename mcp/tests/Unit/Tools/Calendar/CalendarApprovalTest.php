<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use PHPUnit\Framework\Attributes\DataProvider;

/** Conversational approval: no confirm means a plan and zero DAV calls; the ETag in the plan protects the confirmed call. */
final class CalendarApprovalTest extends CalendarTestCase {
    use \OCA\Mcp\Tests\Unit\Tools\AssertsReadablePlans;

    protected function setUp(): void {
        parent::setUp();
        $this->store->addObject(1, 'event.ics', self::ics("UID:event\nSUMMARY:Before\nDTSTART:20261001T120000Z\nDTEND:20261001T130000Z"));
    }

    public static function writes(): array {
        $event = ['calendar' => self::PERSONAL, 'uid' => 'event'];
        return [
            'create' => ['calendar_create_event', ['calendar' => self::PERSONAL, 'summary' => 'Draft', 'start' => '2026-10-02', 'end' => '2026-10-03', 'allDay' => true]],
            'update' => ['calendar_update_event', $event + ['summary' => 'After']],
            'delete' => ['calendar_delete_event', $event],
            'move' => ['calendar_move_event', $event + ['targetCalendar' => self::WORK]],
            'transfer' => ['calendar_transfer_event', $event + ['targetCalendar' => self::TEAM, 'confirm_shared' => true]],
        ];
    }

    public static function existingEventWrites(): array {
        return array_diff_key(self::writes(), ['create' => true]);
    }

    /** Every write tool of the module renders a plan of its own: none falls back to the generic field list. */
    public function testEveryWriteToolHasAReadablePlan(): void {
        $plans = [];
        foreach (self::writes() as [$tool, $arguments]) {
            $plans[$tool] = $this->module->preview($tool, $arguments, 'alice');
        }
        $this->assertEveryWriteToolHasAReadablePlan($this->module, $plans);
        $this->assertNoWrites();
    }

    #[DataProvider('writes')]
    public function testWithoutConfirmReturnsPlanAndDispatchesNothing(string $tool, array $args): void {
        foreach ([[], ['confirm' => false]] as $extra) {
            $plan = self::json($this->registry->call($tool, $args + $extra, 'alice'));
            self::assertTrue($plan['requiresConfirmation']);
            self::assertSame($tool, $plan['action']);
            self::assertArrayHasKey('before', $plan);
            self::assertArrayHasKey('after', $plan);
            self::assertArrayNotHasKey('approvalId', $plan);
            self::assertFalse($plan['scheduling']['participantsNotified']);
            $this->assertNoWrites();
        }
    }

    #[DataProvider('writes')]
    public function testConfirmTrueDispatchesOnceWithTheNormalResult(string $tool, array $args): void {
        $result = $this->registry->call($tool, $args + ['confirm' => true, 'confirm_shared' => true], 'alice');
        self::assertArrayNotHasKey('isError', $result);
        self::assertArrayNotHasKey('requiresConfirmation', self::json($result));
        self::assertCount(1, $this->dav->calls);
    }

    /**
     * `confirm` is decided by the registry alone: the module never checks it again, so a call that reached
     * it dispatches once whatever `confirm` says, and the registry is the only place that can stop it.
     */
    #[DataProvider('writes')]
    public function testTheModuleDoesNotCheckConfirmASecondTime(string $tool, array $args): void {
        $result = $this->module->call($tool, $args + ['confirm_shared' => true], 'alice');
        self::assertArrayNotHasKey('isError', $result, $result['content'][0]['text'] ?? '');
        self::assertCount(1, $this->dav->calls);
    }

    #[DataProvider('existingEventWrites')]
    public function testPlanEtagIsAcceptedAndUsedOnTheConfirmedCall(string $tool, array $args): void {
        $plan = self::json($this->registry->call($tool, $args, 'alice'));
        self::assertNotEmpty($plan['etag']);
        self::assertSame('event', $plan['uid']);
        self::json($this->registry->call($tool, $args + ['confirm' => true, 'confirm_shared' => true, 'etag' => $plan['etag']], 'alice'));
        self::assertCount(1, $this->dav->calls);
    }

    #[DataProvider('existingEventWrites')]
    public function testEventChangedAfterThePlanRefusesTheConfirmedCall(string $tool, array $args): void {
        $plan = self::json($this->registry->call($tool, $args, 'alice'));
        $this->store->objects[1]['event.ics']['etag'] = '"changed-by-someone-else"';
        self::assertToolError($this->registry->call($tool, $args + ['confirm' => true, 'confirm_shared' => true, 'etag' => $plan['etag']], 'alice'), 'etag');
        $this->assertNoWrites();
    }

    #[DataProvider('writes')]
    public function testPermissionsStillApplyToThePlanAndToTheConfirmedCall(string $tool, array $args): void {
        $readOnly = array_replace($args, ['calendar' => self::READONLY]);
        foreach ([[], ['confirm' => true, 'confirm_shared' => true]] as $extra) {
            $result = $this->registry->call($tool, $readOnly + $extra, 'alice');
            self::assertTrue($result['isError'] ?? false);
        }
        $this->assertNoWrites();
    }

    public function testTimingAndParticipantsAreDisplayedWithInvitationConsequences(): void {
        $this->store->objects[1]['event.ics']['data'] = self::ics("UID:event\nSUMMARY:Before\nDTSTART:20261001T120000Z\nDTEND:20261001T130000Z\nATTENDEE:mailto:bob@example.invalid");
        $plan = self::json($this->registry->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'event', 'start' => '2026-10-01T14:00:00Z', 'end' => '2026-10-01T15:00:00Z', 'attendees' => ['carla'], 'send_invitations' => true], 'alice'));
        self::assertNotSame($plan['before']['start'], $plan['after']['start']);
        self::assertSame(['mailto:carla@example.invalid'], $plan['participants']['added']);
        self::assertSame(['mailto:bob@example.invalid'], $plan['participants']['removed']);
        self::assertTrue($plan['scheduling']['requested']);
        self::assertStringContainsString('convite', $plan['scheduling']['message']);
        $this->assertNoWrites();
    }

    public function testSuppressedInvitationsAreStatedInThePlan(): void {
        $plan = self::json($this->registry->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'event', 'summary' => 'After'], 'alice'));
        self::assertFalse($plan['scheduling']['requested']);
        self::assertStringContainsString('Nenhum convite', $plan['scheduling']['message']);
        self::assertSame('Before', $plan['before']['summary']);
        self::assertSame('After', $plan['after']['summary']);
    }

    public function testDeleteExplainsTrashAndCancelWithoutScheduling(): void {
        $plan = self::json($this->registry->call('calendar_delete_event', ['calendar' => self::PERSONAL, 'uid' => 'event', 'send_invitations' => true], 'alice'));
        self::assertTrue($plan['recoverable']);
        self::assertStringContainsString('CANCEL', $plan['scheduling']['message']);
        $this->assertNoWrites();
    }

    public function testMoveAndTransferShowDestinationAndNoNotification(): void {
        $plan = self::json($this->registry->call('calendar_move_event', ['calendar' => self::PERSONAL, 'uid' => 'event', 'targetCalendar' => self::WORK], 'alice'));
        self::assertSame(self::WORK, $plan['destination']['path']);
        self::assertStringContainsString('não', $plan['scheduling']['message']);
        $this->assertNoWrites();
    }

    /**
     * Writes that touch bob's shared calendar, on either side of the change, with no `confirm_shared`.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}> tool and arguments
     */
    public static function writesOnSomebodyElsesCalendar(): array {
        $inTeam = ['calendar' => self::TEAM, 'uid' => 'shared'];
        return [
            'create' => ['calendar_create_event', ['calendar' => self::TEAM, 'summary' => 'Draft', 'start' => '2026-10-02', 'end' => '2026-10-03', 'allDay' => true]],
            'update' => ['calendar_update_event', $inTeam + ['summary' => 'After']],
            'delete' => ['calendar_delete_event', $inTeam],
            'move inside bob\'s calendars' => ['calendar_move_event', $inTeam + ['targetCalendar' => '/remote.php/dav/calendars/alice/other_shared_by_bob/']],
            'transfer out of it' => ['calendar_transfer_event', $inTeam + ['targetCalendar' => self::PERSONAL]],
            'transfer into it' => ['calendar_transfer_event', ['calendar' => self::PERSONAL, 'uid' => 'event', 'targetCalendar' => self::TEAM]],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}> the writes that exist between one's own calendars (a transfer is between owners by definition)
     */
    public static function writesBetweenOwnCalendars(): array {
        return array_diff_key(self::existingEventWrites(), ['transfer' => true]);
    }

    /**
     * The production gate: the registry sends a confirmed write through CalendarDraftApproval::execute, which prepares it
     * with the acknowledgement forced on, so the check of the handler never fires; this is the check that stands.
     * The execution has to be refused before anything reaches DAV, with `confirm: true` alone and with an explicit false
     * (a value that is not a boolean never gets this far: the schema validation refuses it).
     */
    #[DataProvider('writesOnSomebodyElsesCalendar')]
    public function testConfirmedWriteOnSomebodyElsesCalendarNeedsTheSharedAcknowledgement(string $tool, array $args): void {
        $this->store->addCalendar(self::ALICE, 8, 'other_shared_by_bob', self::BOB);
        $this->store->addObject(3, 'shared.ics', self::ics("UID:shared\nSUMMARY:Reuniao do Roberto\nDTSTART:20261001T120000Z\nDTEND:20261001T130000Z"));

        foreach ([[], ['confirm_shared' => false]] as $extra) {
            self::assertToolError($this->registry->call($tool, $args + $extra + ['confirm' => true], 'alice'), 'compartilhado');
            $this->assertNoWrites();
        }

        self::assertArrayNotHasKey('isError', $this->registry->call($tool, $args + ['confirm' => true, 'confirm_shared' => true], 'alice'));
        self::assertCount(1, $this->dav->calls);
    }

    /** The plan of the same call is not an error: it carries the notice and writes nothing. */
    #[DataProvider('writesOnSomebodyElsesCalendar')]
    public function testPlanOnSomebodyElsesCalendarNamesTheOwnerAndWritesNothing(string $tool, array $args): void {
        $this->store->addCalendar(self::ALICE, 8, 'other_shared_by_bob', self::BOB);
        $this->store->addObject(3, 'shared.ics', self::ics("UID:shared\nSUMMARY:Reuniao do Roberto\nDTSTART:20261001T120000Z\nDTEND:20261001T130000Z"));
        $plan = self::json($this->registry->call($tool, $args, 'alice'));
        self::assertTrue($plan['requiresConfirmation']);
        self::assertSame('bob', $plan['shared'][0]['owner']);
        $this->assertNoWrites();
    }

    /** The gate is for calendars of other people only: between her own calendars no acknowledgement is asked. */
    #[DataProvider('writesBetweenOwnCalendars')]
    public function testConfirmedWriteOnOwnCalendarsNeedsNoSharedAcknowledgement(string $tool, array $args): void {
        self::assertArrayNotHasKey('isError', $this->registry->call($tool, $args + ['confirm' => true], 'alice'));
        self::assertCount(1, $this->dav->calls);
    }

    /** The text of the plan, which is what the person reads, names the owner of the shared calendar. */
    public function testThePlanTextNamesTheOwnerOfTheSharedCalendar(): void {
        $result = $this->registry->call('calendar_create_event', ['calendar' => self::TEAM, 'summary' => 'Planejamento', 'start' => '2026-10-02', 'end' => '2026-10-03', 'allDay' => true], 'alice');
        self::assertStringContainsString('- Calendário *Equipe (bob)* compartilhado por Roberto Almeida.', $result['content'][0]['text']);

        $own = $this->registry->call('calendar_create_event', ['calendar' => self::PERSONAL, 'summary' => 'Planejamento', 'start' => '2026-10-02', 'end' => '2026-10-03', 'allDay' => true], 'alice');
        self::assertStringNotContainsString('compartilhado por', $own['content'][0]['text']);
    }

    public function testSharedCalendarPlanWarnsAndExecutionStillNeedsTheSharedAcknowledgement(): void {
        $args = ['calendar' => self::PERSONAL, 'uid' => 'event', 'targetCalendar' => self::TEAM];
        $plan = self::json($this->registry->call('calendar_transfer_event', $args, 'alice'));
        self::assertSame('bob', $plan['shared'][0]['owner']);
        $this->assertNoWrites();
        self::assertToolError($this->registry->call('calendar_transfer_event', $args + ['confirm' => true], 'alice'), 'compartilhado');
        $this->assertNoWrites();
        self::json($this->registry->call('calendar_transfer_event', $args + ['confirm' => true, 'confirm_shared' => true], 'alice'));
        self::assertCount(1, $this->dav->calls);
    }

    public function testPreparingNeverSchedules(): void {
        $args = ['calendar' => self::PERSONAL, 'uid' => 'event', 'summary' => 'After', 'send_invitations' => true];
        self::json($this->registry->call('calendar_update_event', $args, 'alice'));
        $this->assertNoWrites();
    }

    public function testPublicSchemasExposeConfirmWithoutApprovalIdAndTellTheAiToAskFirst(): void {
        $definitions = array_column($this->registry->list('alice'), null, 'name');
        foreach (array_keys(self::writes()) as $key) {
            $definition = $definitions[self::writes()[$key][0]];
            $properties = (array)$definition['inputSchema']['properties'];
            self::assertArrayHasKey('confirm', $properties);
            self::assertArrayNotHasKey('approval_id', $properties);
            self::assertNotContains('confirm', $definition['inputSchema']['required'] ?? []);
            self::assertStringContainsString('explicit approval', $definition['description']);
        }
    }

    public function testSelftestHandlersStillWriteDirectly(): void {
        $this->writeHandlers['calendar_update_event']->execute(['calendar' => self::PERSONAL, 'uid' => 'event', 'summary' => 'After'], 'alice');
        self::assertCount(1, $this->dav->calls);
    }
}
