<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use InvalidArgumentException;
use OCA\Mcp\Service\GrantPolicy;

final class CalendarModuleTest extends CalendarTestCase {
    public function testDefinitionsDeclareCatalogGrantsAppAndStrictSchemas(): void {
        $operations = [];
        foreach ($this->module->definitions() as $definition) {
            $this->assertSame(['name', 'description', 'inputSchema', 'module', 'operation', 'app'], array_keys($definition));
            $this->assertSame(['calendar', 'calendar'], [$definition['module'], $definition['app']]);
            $this->assertContains($definition['operation'], GrantPolicy::CATALOG['calendar']);
            $this->assertFalse($definition['inputSchema']['additionalProperties']);
            $this->assertIsObject($definition['inputSchema']['properties']);
            $operations[$definition['name']] = $definition['operation'];
        }
        $this->assertSame([
            'calendar_list_calendars' => 'read',
            'calendar_list_events' => 'read',
        ], $operations);
    }

    public function testUnprovedWritesAreHiddenAndFailClosedWithoutStoreMutations(): void {
        foreach (['calendar_create_event', 'calendar_update_event', 'calendar_move_event', 'calendar_delete_event', 'calendar_transfer_event'] as $name) {
            try {
                $this->module->call($name, [], 'alice');
                $this->fail($name . ' should be unavailable until the official DAV path is proved.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Unknown tool', $exception->getMessage());
            }
        }

        $this->assertNoWrites();
    }

    public function testWriteGrantNamesRemainAvailableForFutureReactivation(): void {
        foreach (['create', 'edit', 'move', 'delete', 'transfer'] as $operation) {
            $this->assertContains($operation, GrantPolicy::CATALOG['calendar']);
        }
    }

    public function testVerifiedOperationsAreExposedAndRevokeTakesEffectImmediately(): void {
        $this->verification = json_encode(['app' => '0.6.10', 'nextcloud' => '33.0', 'operations' => ['create', 'edit'], 'invitations' => false]);
        self::assertCount(4, $this->module->definitions());
        $this->verification = '';
        self::assertCount(2, $this->module->definitions());
    }

    public function testUnverifiedInvitationRequestDispatchesNothing(): void {
        $this->verification = json_encode(['app' => '0.6.10', 'nextcloud' => '33.0', 'operations' => ['create'], 'invitations' => false]);
        $result = $this->module->call('calendar_create_event', [
            'calendar' => self::PERSONAL, 'summary' => 'Meet',
            'start' => '2026-10-02', 'end' => '2026-10-03', 'allDay' => true,
            'attendees' => ['bob'], 'send_invitations' => true,
        ], 'alice');
        self::assertToolError($result, 'ainda não verificado');
        $this->assertNoWrites();
    }

    public function testVerifiedInvitationsUseTheRealHandler(): void {
        $this->verification = json_encode(['app' => '0.6.10', 'nextcloud' => '33.0', 'operations' => ['create'], 'invitations' => true]);
        $result = self::json($this->module->call('calendar_create_event', [
            'calendar' => self::PERSONAL, 'summary' => 'Meet',
            'start' => '2026-10-02', 'end' => '2026-10-03', 'allDay' => true,
            'attendees' => ['bob'], 'send_invitations' => true, 'confirm' => true,
        ], 'alice'));
        self::assertTrue($result['scheduling']['requested']);
        self::assertSame('put', $this->dav->calls[0][0]);
    }

    public function testUnverifiedExistingGuestsAndCancellationDispatchNothing(): void {
        $this->verification = json_encode(['app' => '0.6.10', 'nextcloud' => '33.0', 'operations' => ['edit', 'delete'], 'invitations' => false]);
        $this->store->addObject(1, 'guest.ics', self::ics("UID:guest\nDTSTART:20261001T120000Z\nDTEND:20261001T130000Z\nATTENDEE:mailto:bob@example.invalid"));
        foreach (['calendar_update_event', 'calendar_delete_event'] as $tool) {
            self::assertToolError($this->module->call($tool, ['calendar' => self::PERSONAL, 'uid' => 'guest', 'summary' => 'Changed', 'confirm' => true, 'send_invitations' => true], 'alice'), 'ainda não verificado');
        }
        $this->assertNoWrites();
    }

    public function testPublicRegistryExposesInvitationAnnotationsAndStillRequiresGrants(): void {
        $this->verification = json_encode(['app' => '0.6.10', 'nextcloud' => '33.0', 'operations' => ['create', 'edit'], 'invitations' => false]);
        $policy = new GrantPolicy((new \OCA\Mcp\Tests\Unit\InMemoryConfig())->mock($this));
        $apps = $this->createMock(\OCP\App\IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn(true);
        $users = $this->createMock(\OCP\IUserManager::class);
        $users->method('get')->willReturn($this->createMock(\OCP\IUser::class));
        $registry = new \OCA\Mcp\Tools\ToolRegistry([$this->module], $policy, $apps, $users, new \Psr\Log\NullLogger());
        // The two exposed reads plus the guide, which the registry lists ahead of every module.
        self::assertCount(3, $registry->list('alice'));
        $policy->setGrant('alice', 'calendar', 'create', true);
        $policy->setGrant('alice', 'calendar', 'edit', true);
        $listed = array_column($registry->list('alice'), null, 'name');
        self::assertTrue($listed['calendar_create_event']['annotations']['destructiveHint']);
        self::assertTrue($listed['calendar_update_event']['annotations']['destructiveHint']);
        self::assertFalse($listed['calendar_list_events']['annotations']['destructiveHint']);
    }

    public function testUnknownToolIsInvalidParams(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->call('calendar_nope');
    }
}
