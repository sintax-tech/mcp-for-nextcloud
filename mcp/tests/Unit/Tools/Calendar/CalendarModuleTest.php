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

    public function testUnknownToolIsInvalidParams(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->call('calendar_nope');
    }
}
