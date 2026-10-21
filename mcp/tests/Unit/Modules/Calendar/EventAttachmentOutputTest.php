<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Calendar;

use OCA\Mcp\Tests\Support\Calendar\CalendarTestCase;

use PHPUnit\Framework\Attributes\DataProvider;
use Sabre\VObject\Reader;

/**
 * V10 for Calendar: no event tool carries the raw iCalendar, so an ATTACH pointing at a Files node never reaches
 * a list, a plan or a confirmed result. Proven per tool, not presumed from the payload's name. The DAV write still
 * keeps the ATTACH the event had.
 */
final class EventAttachmentOutputTest extends CalendarTestCase {
    private const ATTACH_URL = 'https://cloud.example.com/f/4242';
    private const ATTACH_NAME = 'secret-contract.pdf';

    protected function setUp(): void {
        parent::setUp();
        $this->store->addObject(1, 'event.ics', self::ics(
            "UID:event\nSUMMARY:Before\nDTSTART:20261001T120000Z\nDTEND:20261001T130000Z\n"
            . 'ATTACH;FMTTYPE=application/pdf;FILENAME=/Secret/' . self::ATTACH_NAME . ':' . self::ATTACH_URL . "\n"
            . 'ATTACH:https://cloud.example.com/remote.php/dav/files/alice/Secret/' . self::ATTACH_NAME,
            "BEGIN:VEVENT\nUID:event\nRECURRENCE-ID:20261008T120000Z\nDTSTART:20261008T120000Z\nDTEND:20261008T130000Z\n"
            . 'ATTACH:' . self::ATTACH_URL . "\nEND:VEVENT",
        ));
    }

    private static function assertNoFileReference(array $result, string $context): void {
        $text = json_encode($result, JSON_UNESCAPED_SLASHES) ?: '';
        self::assertStringNotContainsStringIgnoringCase('ATTACH', $text, $context);
        self::assertStringNotContainsString('cloud.example.com', $text, $context);
        self::assertStringNotContainsString(self::ATTACH_NAME, $text, $context);
        self::assertStringNotContainsString('/f/4242', $text, $context);
    }

    /** @return array<string, array{string, array<string, mixed>}> every event tool with arguments reaching the event */
    public static function tools(): array {
        $event = ['calendar' => self::PERSONAL, 'uid' => 'event'];
        return [
            'list calendars' => ['calendar_list_calendars', []],
            'list events' => ['calendar_list_events', ['from' => '2026-09-01', 'to' => '2026-11-01']],
            'create' => ['calendar_create_event', ['calendar' => self::PERSONAL, 'summary' => 'Draft', 'start' => '2026-10-02', 'end' => '2026-10-03', 'allDay' => true]],
            'update' => ['calendar_update_event', $event + ['summary' => 'After']],
            'delete' => ['calendar_delete_event', $event],
            'move' => ['calendar_move_event', $event + ['targetCalendar' => self::WORK]],
            'transfer' => ['calendar_transfer_event', $event + ['targetCalendar' => self::TEAM, 'confirm_shared' => true]],
        ];
    }

    public function testEveryCalendarToolIsCovered(): void {
        $names = array_map(static fn (array $definition): string => $definition['name'], $this->module->definitions());
        sort($names);
        $covered = array_column(self::tools(), 0);
        sort($covered);
        self::assertSame($names, $covered, 'a new calendar tool must be added to this proof');
    }

    #[DataProvider('tools')]
    public function testNoToolExposesAttachInThePlanOrTheResult(string $tool, array $arguments): void {
        $first = $this->registry->call($tool, $arguments, 'alice');
        self::assertArrayNotHasKey('isError', $first, $first['content'][0]['text'] ?? '');
        self::assertNoFileReference($first, "$tool without confirm");
        if ($tool === 'calendar_list_events') {
            self::assertStringContainsString('Before', $first['content'][0]['text'], 'the event with ATTACH was listed');
        }
        if (str_starts_with($tool, 'calendar_list_')) {
            return;
        }
        $confirmed = $this->registry->call($tool, $arguments + ['confirm' => true], 'alice');
        self::assertArrayNotHasKey('isError', $confirmed, $confirmed['content'][0]['text'] ?? '');
        self::assertNoFileReference($confirmed, "$tool confirmed");
    }

    public function testErrorsAboutTheEventCarryNoAttach(): void {
        $result = $this->registry->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'event', 'start' => '2026-10-01T15:00:00Z', 'end' => '2026-10-01T14:00:00Z', 'confirm' => true], 'alice');

        self::assertTrue($result['isError'] ?? false);
        self::assertNoFileReference($result, 'update error');
    }

    public function testUpdatingAnotherFieldKeepsTheAttachInTheDavObject(): void {
        $this->registry->call('calendar_update_event', ['calendar' => self::PERSONAL, 'uid' => 'event', 'summary' => 'After', 'confirm' => true], 'alice');

        $written = Reader::read($this->store->objects[1]['event.ics']['data']);
        $values = [];
        foreach ($written->select('VEVENT') as $event) {
            foreach ($event->select('ATTACH') as $attach) {
                $values[] = (string)$attach->getValue();
            }
        }
        self::assertContains(self::ATTACH_URL, $values);
        self::assertCount(3, $values);
    }
}
