<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use DateTimeImmutable;
use OCA\Mcp\Tools\Calendar\CalendarAccess;
use OCA\Mcp\Tools\Calendar\CalendarModule;
use OCA\Mcp\Tools\Calendar\Classification;
use OCA\Mcp\Tools\Calendar\DateInput;
use OCA\Mcp\Tools\Calendar\EventBuilder;
use OCA\Mcp\Tools\Calendar\EventExpander;
use OCA\Mcp\Tools\Calendar\EventMapper;
use OCA\Mcp\Tools\Calendar\EventRelocator;
use OCA\Mcp\Tools\Calendar\EventRepository;
use OCA\Mcp\Tools\Calendar\Handler\CreateEvent;
use OCA\Mcp\Tools\Calendar\Handler\DeleteEvent;
use OCA\Mcp\Tools\Calendar\Handler\ListCalendars;
use OCA\Mcp\Tools\Calendar\Handler\ListEvents;
use OCA\Mcp\Tools\Calendar\Handler\MoveEvent;
use OCA\Mcp\Tools\Calendar\Handler\TransferEvent;
use OCA\Mcp\Tools\Calendar\Handler\UpdateEvent;
use OCA\Mcp\Tools\Calendar\TrashPolicy;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Builds the calendar module over a FakeCalendarStore with alice's and bob's calendars:
 * - alice/personal (id 1) and alice/work (id 2), owned by alice;
 * - bob/team shared with alice read-write as team_shared_by_bob (id 3);
 * - bob/private shared with alice read-only as private_shared_by_bob (id 4);
 * - contact_birthdays of alice (id 5) and a tasks-only list (id 6).
 */
abstract class CalendarTestCase extends TestCase {
    protected const ALICE = 'principals/users/alice';
    protected const BOB = 'principals/users/bob';
    protected const PERSONAL = '/remote.php/dav/calendars/alice/personal/';
    protected const WORK = '/remote.php/dav/calendars/alice/work/';
    protected const TEAM = '/remote.php/dav/calendars/alice/team_shared_by_bob/';
    protected const READONLY = '/remote.php/dav/calendars/alice/private_shared_by_bob/';
    protected const BIRTHDAYS = '/remote.php/dav/calendars/alice/contact_birthdays/';
    protected const NOW = '2026-03-10T12:00:00Z';

    protected FakeCalendarStore $store;
    protected CalendarModule $module;
    /** Value returned by dav/calendarRetentionObligation. */
    protected string $retention = '';

    protected function setUp(): void {
        $this->store = new FakeCalendarStore();
        $this->store->addCalendar(self::ALICE, 1, 'personal', self::ALICE, ['name' => 'Pessoal']);
        $this->store->addCalendar(self::ALICE, 2, 'work', self::ALICE, ['name' => 'Trabalho']);
        $this->store->addCalendar(self::ALICE, 3, 'team_shared_by_bob', self::BOB, ['name' => 'Equipe (bob)']);
        $this->store->addCalendar(self::ALICE, 4, 'private_shared_by_bob', self::BOB, ['readOnly' => true]);
        $this->store->addCalendar(self::ALICE, 5, 'contact_birthdays', self::ALICE, ['components' => ['VEVENT']]);
        $this->store->addCalendar(self::ALICE, 6, 'tasks', self::ALICE, ['components' => ['VTODO']]);
        $this->store->addCalendar(self::ALICE, 7, 'old', self::ALICE, ['deleted' => true]);

        $time = $this->createMock(ITimeFactory::class);
        $time->method('now')->willReturnCallback(static fn () => new DateTimeImmutable(self::NOW));
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(fn (string $app, string $key) => $app === 'dav' && $key === 'calendarRetentionObligation' ? $this->retention : '');
        $logger = $this->createMock(LoggerInterface::class);

        $access = new CalendarAccess($this->store);
        $classification = new Classification();
        $repository = new EventRepository($this->store, $classification);
        $dates = new DateInput();
        $mapper = new EventMapper();
        $builder = new EventBuilder();
        $relocator = new EventRelocator($access, $this->store, $repository);
        $this->module = new CalendarModule(
            new ListCalendars($access),
            new ListEvents($access, $this->store, $repository, $classification, new EventExpander(), $mapper, $dates, $time, $logger),
            new CreateEvent($access, $this->store, $repository, $builder, $mapper, $dates, $time),
            new UpdateEvent($access, $this->store, $repository, $builder, $mapper, $dates, $time),
            new MoveEvent($relocator),
            new DeleteEvent($access, $this->store, $repository, new TrashPolicy($config)),
            new TransferEvent($relocator),
        );
    }

    /**
     * @param string $body VEVENT lines (without BEGIN/END:VEVENT), "\n"-separated
     * @param string $extra extra components after the VEVENT (e.g. overrides), already wrapped
     * @return string iCalendar text with CRLF line endings
     */
    protected static function ics(string $body, string $extra = ''): string {
        $text = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//test//EN\nBEGIN:VEVENT\n" . trim($body) . "\nEND:VEVENT\n" . ($extra === '' ? '' : trim($extra) . "\n") . "END:VCALENDAR\n";
        return str_replace("\n", "\r\n", $text);
    }

    /**
     * @param string $name tool name
     * @param array<string, mixed> $arguments tool arguments
     * @return array{content: list<array{type:string, text:string}>, isError?: bool}
     */
    protected function call(string $name, array $arguments = []): array {
        return $this->module->call($name, $arguments, 'alice');
    }

    /**
     * @param array{content: list<array{type:string, text:string}>, isError?: bool} $result MCP result
     * @param int $block content block index
     * @return mixed decoded JSON of the block
     */
    protected static function json(array $result, int $block = 0): mixed {
        self::assertArrayNotHasKey('isError', $result, $result['content'][0]['text'] ?? '');
        return json_decode($result['content'][$block]['text'], true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array{content: list<array{type:string, text:string}>, isError?: bool} $result MCP result
     * @param string $fragment expected part of the Portuguese message
     */
    protected static function assertToolError(array $result, string $fragment): void {
        self::assertTrue($result['isError'] ?? false, 'expected isError');
        self::assertStringContainsString($fragment, $result['content'][0]['text']);
    }

    /**
     * Asserts that no write method of the store was called.
     */
    protected function assertNoWrites(): void {
        self::assertSame([], $this->store->writes);
    }
}
