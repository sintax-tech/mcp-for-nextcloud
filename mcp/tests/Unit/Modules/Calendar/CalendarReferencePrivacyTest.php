<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Calendar;

use OCA\Mcp\Tests\Support\Calendar\CalendarTestCase;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Modules\Calendar\CalendarModule;
use OCA\Mcp\Modules\Calendar\CalendarToolCatalog;

/** Calendar output exposes selected event fields, never raw ATTACH or nested components. */
final class CalendarReferencePrivacyTest extends CalendarTestCase {
    public function testEventReferencesStayOutOfReadPlanAndResultWithoutChangingStoredAttachments(): void {
        $attachment = 'https://cloud.example.com/f/403';
        $alarm = 'https://cloud.example.com/f/404';
        $freeText = 'Keep https://cloud.example.com/f/405 as ordinary text';
        $this->store->addObject(1, 'attached.ics', self::ics("UID:attached\nSUMMARY:Attached\nDESCRIPTION:$freeText\n"
            . "DTSTART:20260312T090000Z\nDTEND:20260312T100000Z\nATTACH:$attachment\n"
            . "BEGIN:VALARM\nACTION:AUDIO\nTRIGGER:-PT5M\nATTACH:$alarm\nEND:VALARM"));
        $read = $this->module->call('calendar_list_events', ['calendar' => self::PERSONAL, 'from' => '2026-03-12', 'to' => '2026-03-13'], 'alice');
        $args = ['calendar' => self::PERSONAL, 'uid' => 'attached', 'summary' => 'Renamed'];
        $plan = $this->module->preview('calendar_update_event', $args, 'alice');
        $this->assertSame($freeText, $plan['before']['description']);
        $this->assertSame($freeText, $plan['after']['description']);
        $result = $this->module->call('calendar_update_event', $args + ['confirm' => true], 'alice');
        $this->assertFalse($result['isError'] ?? false);
        foreach ([$read, $plan, $result] as $shown) {
            $encoded = json_encode($shown, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString($attachment, $encoded);
            $this->assertStringNotContainsString($alarm, $encoded);
        }
        $stored = $this->store->objectByUid(1, 'attached', 'VEVENT');
        $this->assertStringContainsString('SUMMARY:Renamed', $stored['data']);
        $this->assertStringContainsString('ATTACH:' . $attachment, $stored['data']);
        $this->assertStringContainsString('ATTACH:' . $alarm, $stored['data']);
    }

    public function testUnreadableFilesScopeStopsEveryCalendarEntryBeforeResolvingTools(): void {
        $resolved = 0;
        $factories = [];
        foreach (CalendarToolCatalog::definitions() as $name => $definition) {
            $factories[$name] = static function () use (&$resolved) { $resolved++; throw new \LogicException('Tool resolved'); };
        }
        $module = new CalendarModule($factories, static fn (string $uid) => throw new PolicyUnavailable('Scope unavailable'));
        foreach (CalendarToolCatalog::definitions() as $name => $definition) {
            foreach ($definition['operation'] === 'read' ? ['call'] : ['call', 'preview'] as $entry) {
                try {
                    $module->$entry($name, [], 'alice');
                    $this->fail('Unreadable scope admitted ' . $name);
                } catch (PolicyUnavailable) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        $this->assertSame(0, $resolved);
    }
}
