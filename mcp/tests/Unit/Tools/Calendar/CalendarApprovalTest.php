<?php
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use PHPUnit\Framework\Attributes\DataProvider;

/** Conversational approval: no confirm means a plan and zero DAV calls; the ETag in the plan protects the confirmed call. */
final class CalendarApprovalTest extends CalendarTestCase {
    protected function setUp(): void {
        parent::setUp();
        $this->verification = json_encode(['app' => '0.6.10', 'nextcloud' => '33.0', 'operations' => ['create', 'edit', 'delete', 'move', 'transfer'], 'invitations' => true]);
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

    public function testPreparingNeverSchedulesAndUnverifiedInvitationsRemainBlocked(): void {
        $args = ['calendar' => self::PERSONAL, 'uid' => 'event', 'summary' => 'After', 'send_invitations' => true];
        self::json($this->registry->call('calendar_update_event', $args, 'alice'));
        $this->assertNoWrites();
        $this->verification = json_encode(['app' => '0.6.10', 'nextcloud' => '33.0', 'operations' => ['edit'], 'invitations' => false]);
        self::assertToolError($this->registry->call('calendar_update_event', $args, 'alice'), 'ainda não verificado');
        self::assertToolError($this->registry->call('calendar_update_event', $args + ['confirm' => true], 'alice'), 'ainda não verificado');
        $this->assertNoWrites();
    }

    public function testClosedGateHidesAndBlocksTheWholePlanFlow(): void {
        $this->verification = '';
        self::assertSame([], array_filter(array_column($this->module->definitions(), 'name'), static fn (string $n): bool => $n !== 'calendar_list_calendars' && $n !== 'calendar_list_events'));
        $this->expectException(\InvalidArgumentException::class);
        $this->registry->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'event', 'summary' => 'After'], 'alice');
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
