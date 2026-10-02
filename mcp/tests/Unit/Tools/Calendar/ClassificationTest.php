<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use OCA\Mcp\Tools\Calendar\Calendar;
use OCA\Mcp\Tools\Calendar\CalendarException;
use OCA\Mcp\Tools\Calendar\Classification;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/**
 * The CLASS of an iCalendar object, component by component: the master and every override have their own.
 */
final class ClassificationTest extends TestCase {
    private Classification $classification;
    private Calendar $own;
    private Calendar $shared;

    protected function setUp(): void {
        $this->classification = new Classification();
        $this->own = new Calendar(1, 'personal', 'Pessoal', 'alice', 'principals/users/alice', true, '/remote.php/dav/calendars/alice/personal/');
        $this->shared = new Calendar(3, 'team_shared_by_bob', 'Equipe', 'bob', 'principals/users/bob', true, '/remote.php/dav/calendars/alice/team_shared_by_bob/');
    }

    private static function series(string $masterClass, string $overrideClass): VCalendar {
        $text = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//t//EN\nBEGIN:VEVENT\nUID:s\n" . ($masterClass === '' ? '' : $masterClass . "\n")
            . "DTSTART:20260310T100000Z\nDTEND:20260310T110000Z\nRRULE:FREQ=DAILY;COUNT=3\nEND:VEVENT\n"
            . "BEGIN:VEVENT\nUID:s\nRECURRENCE-ID:20260311T100000Z\n" . ($overrideClass === '' ? '' : $overrideClass . "\n")
            . "DTSTART:20260311T150000Z\nDTEND:20260311T160000Z\nEND:VEVENT\nEND:VCALENDAR\n";
        $vcalendar = Reader::read(str_replace("\n", "\r\n", $text));
        assert($vcalendar instanceof VCalendar);
        return $vcalendar;
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}> master CLASS, override CLASS, master verdict, override verdict, whole-object verdict */
    public static function classes(): array {
        return [
            'nothing' => ['', '', Classification::FULL, Classification::FULL, Classification::FULL],
            'private override of a public master' => ['', 'CLASS:PRIVATE', Classification::FULL, Classification::HIDDEN, Classification::HIDDEN],
            'confidential override of a public master' => ['', 'CLASS:CONFIDENTIAL', Classification::FULL, Classification::BUSY, Classification::BUSY],
            'override inherits private' => ['CLASS:PRIVATE', '', Classification::HIDDEN, Classification::HIDDEN, Classification::HIDDEN],
            'override inherits confidential' => ['CLASS:CONFIDENTIAL', '', Classification::BUSY, Classification::BUSY, Classification::BUSY],
            'public override of a private master' => ['CLASS:PRIVATE', 'CLASS:PUBLIC', Classification::HIDDEN, Classification::FULL, Classification::HIDDEN],
            'confidential override of a private master' => ['CLASS:PRIVATE', 'CLASS:CONFIDENTIAL', Classification::HIDDEN, Classification::BUSY, Classification::HIDDEN],
            'lower case' => ['CLASS:private', 'CLASS:confidential', Classification::HIDDEN, Classification::BUSY, Classification::HIDDEN],
        ];
    }

    /** @dataProvider classes */
    public function testEachComponentIsTreatedByItsOwnClass(string $masterClass, string $overrideClass, string $master, string $override, string $whole): void {
        $vcalendar = self::series($masterClass, $overrideClass);
        [$masterEvent, $overrideEvent] = $vcalendar->select('VEVENT');
        $this->assertSame($master, $this->classification->visibilityOf($masterEvent, $vcalendar, $this->shared, 'alice'));
        $this->assertSame($override, $this->classification->visibilityOf($overrideEvent, $vcalendar, $this->shared, 'alice'));
        $this->assertSame($whole, $this->classification->visibility($vcalendar, $this->shared, 'alice'));
    }

    /** @dataProvider classes */
    public function testNothingIsRestrictedInTheOwnCalendar(string $masterClass, string $overrideClass): void {
        $vcalendar = self::series($masterClass, $overrideClass);
        foreach ($vcalendar->select('VEVENT') as $event) {
            $this->assertSame(Classification::FULL, $this->classification->visibilityOf($event, $vcalendar, $this->own, 'alice'));
        }
        $this->assertSame(Classification::FULL, $this->classification->visibility($vcalendar, $this->own, 'alice'));
    }

    /** Changing an object of somebody else needs every component visible in full: a private override alone refuses it. */
    public function testAPrivateOverrideBlocksChangingTheWholeObject(): void {
        $this->expectException(CalendarException::class);
        $this->expectExceptionMessage(CalendarException::notFound()->getMessage());
        $this->classification->assertModifiable(self::series('', 'CLASS:PRIVATE'), $this->shared, 'alice');
    }

    public function testAConfidentialOverrideForbidsChangingTheWholeObject(): void {
        $this->expectException(CalendarException::class);
        $this->expectExceptionMessage(CalendarException::forbidden()->getMessage());
        $this->classification->assertModifiable(self::series('', 'CLASS:CONFIDENTIAL'), $this->shared, 'alice');
    }
}
