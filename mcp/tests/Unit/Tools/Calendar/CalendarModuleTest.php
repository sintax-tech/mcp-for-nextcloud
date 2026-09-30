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
            'calendar_create_event' => 'create',
            'calendar_update_event' => 'edit',
            'calendar_move_event' => 'move',
            'calendar_delete_event' => 'delete',
            'calendar_transfer_event' => 'transfer',
        ], $operations);
    }

    public function testDestructiveToolsRequireLiteralConfirm(): void {
        foreach ($this->module->definitions() as $definition) {
            if (in_array($definition['operation'], ['delete', 'transfer'], true)) {
                $this->assertContains('confirm', $definition['inputSchema']['required']);
                $this->assertSame(['type' => 'boolean', 'const' => true], array_intersect_key($definition['inputSchema']['properties']->confirm, ['type' => 1, 'const' => 1]));
            }
        }
    }

    public function testWriteToolsWarnThatAttendeesAreNotNotified(): void {
        foreach ($this->module->definitions() as $definition) {
            $warns = str_contains($definition['description'], 'não são notificados');
            $this->assertSame($definition['operation'] !== 'read', $warns, $definition['name']);
        }
    }

    public function testWriteToolsOfferConfirmSharedAndWarnAboutItInTheDescription(): void {
        $writes = ['create', 'edit', 'move', 'delete'];
        foreach ($this->module->definitions() as $definition) {
            $properties = (array)$definition['inputSchema']['properties'];
            if (in_array($definition['operation'], $writes, true)) {
                $this->assertArrayHasKey('confirm_shared', $properties, $definition['name']);
                $this->assertSame('boolean', $properties['confirm_shared']['type'], $definition['name']);
                $this->assertNotContains('confirm_shared', $definition['inputSchema']['required'] ?? [], $definition['name']);
                $this->assertStringContainsString('confirm_shared: true', $definition['description'], $definition['name']);
            } else {
                $this->assertArrayNotHasKey('confirm_shared', $properties, $definition['name']);
                $this->assertStringNotContainsString('confirm_shared', $definition['description'], $definition['name']);
            }
        }
    }

    public function testUnknownToolIsInvalidParams(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->call('calendar_nope');
    }
}
