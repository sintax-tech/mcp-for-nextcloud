<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use InvalidArgumentException;
use OCA\Mcp\Service\GrantPolicy;

final class CalendarModuleTest extends CalendarTestCase {
    public function testDefinitionsDeclareCatalogGrantsAppAndStrictSchemas(): void {
        $operations = [];
        foreach ($this->module->definitions() as $definition) {
            $this->assertSame(['name', 'description', 'inputSchema', 'module', 'operation', 'app'], array_slice(array_keys($definition), 0, 6));
            $this->assertSame(['calendar', 'calendar'], [$definition['module'], $definition['app']]);
            $this->assertContains($definition['operation'], GrantPolicy::CATALOG['calendar']);
            $this->assertFalse($definition['inputSchema']['additionalProperties']);
            $this->assertIsObject($definition['inputSchema']['properties']);
            $operations[$definition['name']] = $definition['operation'];
        }
        $this->assertSame([
            'calendar_list_calendars' => 'read',
            'calendar_list_events' => 'read',
            'calendar_create_event' => 'create',
            'calendar_update_event' => 'edit',
            'calendar_move_event' => 'move',
            'calendar_delete_event' => 'delete',
            'calendar_transfer_event' => 'transfer',
        ], $operations);
    }

    public function testWritesAreVisibleWithoutAnySelftestWhenGrantedAndHiddenWhenNot(): void {
        $policy = new GrantPolicy((new \OCA\Mcp\Tests\Unit\InMemoryConfig())->mock($this));
        $apps = $this->createMock(\OCP\App\IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn(true);
        $users = $this->createMock(\OCP\IUserManager::class);
        $users->method('get')->willReturn($this->createMock(\OCP\IUser::class));
        $registry = new \OCA\Mcp\Tools\ToolRegistry([$this->module], $policy, $apps, $users, new \Psr\Log\NullLogger());
        $writes = static fn (array $tools): array => array_values(array_filter(array_column($tools, 'name'), static fn (string $n): bool => str_starts_with($n, 'calendar_') && !in_array($n, ['calendar_list_calendars', 'calendar_list_events'], true)));
        self::assertSame([], $writes($registry->list('alice')));
        foreach (['create', 'edit', 'move', 'delete', 'transfer'] as $operation) {
            $policy->setGrant('alice', 'calendar', $operation, true);
        }
        self::assertCount(5, $writes($registry->list('alice')));
        $policy->setGrant('alice', 'calendar', 'delete', false);
        self::assertNotContains('calendar_delete_event', $writes($registry->list('alice')));
    }

    public function testInvitationRequestReportsTheServerSettingHonestly(): void {
        foreach (['yes' => true, 'no' => false] as $setting => $enabled) {
            $this->sendInvitations = $setting;
            $this->setUp();
            $result = self::json($this->module->call('calendar_create_event', [
                'calendar' => self::PERSONAL, 'summary' => 'Meet',
                'start' => '2026-10-02', 'end' => '2026-10-03', 'allDay' => true,
                'attendees' => ['bob'], 'send_invitations' => true, 'confirm' => true,
            ], 'alice'));
            self::assertTrue($result['scheduling']['requested']);
            self::assertSame($enabled, $result['scheduling']['imipEnabled']);
        }
    }

    public function testWriteGrantNamesRemainAvailableForFutureReactivation(): void {
        foreach (['create', 'edit', 'move', 'delete', 'transfer'] as $operation) {
            $this->assertContains($operation, GrantPolicy::CATALOG['calendar']);
        }
    }

    public function testPublicRegistryExposesInvitationAnnotationsAndStillRequiresGrants(): void {
        $policy = new GrantPolicy((new \OCA\Mcp\Tests\Unit\InMemoryConfig())->mock($this));
        $apps = $this->createMock(\OCP\App\IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn(true);
        $users = $this->createMock(\OCP\IUserManager::class);
        $users->method('get')->willReturn($this->createMock(\OCP\IUser::class));
        $registry = new \OCA\Mcp\Tools\ToolRegistry([$this->module], $policy, $apps, $users, new \Psr\Log\NullLogger());
        // The two reads plus the guide, which the registry lists ahead of every module; no write is granted yet.
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
