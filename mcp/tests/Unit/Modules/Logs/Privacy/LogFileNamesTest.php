<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Logs\Privacy;

use OCA\Mcp\Access\Logs\LogFileNames;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Unit\InMemoryAppConfig;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Modules\Logs\Analysis\LogAnalyzer;
use OCA\Mcp\Modules\Logs\Privacy\LogRedactor;
use OCA\Mcp\Modules\Logs\Reading\LogTime;
use OCA\Mcp\Platform\Logging\ExceptionSummary;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;

/**
 * File names and paths in what the log tools show and in what the app itself writes to the log.
 */
final class LogFileNamesTest extends TestCase {
    /** @return \stdClass an entry whose message, URL and exceptions all carry a name */
    private static function namedEntry(): \stdClass {
        return json_decode(json_encode([
            'level' => 3, 'time' => '2026-10-07T08:12:03-03:00', 'app' => 'webdav', 'user' => 'alice', 'method' => 'GET',
            'url' => '/remote.php/dav/files/alice/secret/salary.txt', 'message' => 'Cannot open /alice/files/secret/salary.txt',
            'reqId' => 'r1', 'userAgent' => 'curl/8.0', 'remoteAddr' => '203.0.113.9',
            'exception' => ['Exception' => 'OCP\\Files\\NotFoundException', 'Message' => 'File salary.txt not found', 'Code' => 0,
                'File' => '/var/www/nextcloud/lib/private/Files/View.php', 'Line' => 10, 'Trace' => [],
                'Previous' => ['Exception' => 'RuntimeException', 'Message' => '"Secret Payroll.pdf" locked', 'Code' => 0, 'Trace' => [],
                    'Previous' => ['Exception' => 'LogicException', 'Message' => 'report.txt.secret', 'Code' => 0, 'Trace' => []]]],
        ]));
    }

    /** With messages hidden, the message and every exception message are the marker, and nothing of their text stays. */
    public function testAnEntryHidesEveryMessageWhole(): void {
        $out = LogRedactor::entry(self::namedEntry(), true);

        $this->assertSame('[hidden]', $out['message']);
        $this->assertSame('/remote.php/dav/files/[hidden]', $out['url']);
        $this->assertSame(['[hidden]', '[hidden]', '[hidden]'],
            [$out['exception']['message'], $out['exception']['previous']['message'], $out['exception']['previous']['previous']['message']]);
        $this->assertSame(['OCP\\Files\\NotFoundException', 'RuntimeException', 'LogicException'],
            [$out['exception']['class'], $out['exception']['previous']['class'], $out['exception']['previous']['previous']['class']]);
        $this->assertSame('/var/www/nextcloud/lib/private/Files/View.php:10', $out['exception']['at']);
        $this->assertSame(['alice', 'webdav', 'r1', '203.0.x.x'], [$out['user'], $out['app'], $out['reqId'], $out['remoteAddr']]);
        foreach (['salary', 'Secret', 'Payroll', 'report', 'secret'] as $part) {
            $this->assertStringNotContainsString($part, json_encode($out));
        }
    }

    public function testWithoutHidingAnEntryKeepsItsText(): void {
        $out = LogRedactor::entry(self::namedEntry());

        $this->assertSame('Cannot open /alice/files/secret/salary.txt', $out['message']);
        $this->assertSame('/remote.php/dav/files/alice/secret/salary.txt', $out['url']);
        $this->assertSame('"Secret Payroll.pdf" locked', $out['exception']['previous']['message']);
    }

    /** The app's own log of an unexpected exception keeps its class and code only, in any mode. */
    public function testTheAppsOwnLogOfAnExceptionKeepsOnlyClassAndCode(): void {
        $this->assertSame('RuntimeException (code 0)', ExceptionSummary::loggable(new \RuntimeException('Cannot access salary-secret.txt')));
        $this->assertSame('RuntimeException (code 7)', ExceptionSummary::loggable(new \RuntimeException('File with name /Docs/Salary#Confidential could not be located', 7)));
        $this->assertSame('RuntimeException (code 0)', ExceptionSummary::loggable(new \RuntimeException('File "README" is locked')));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function urls(): iterable {
        yield 'webdav' => ['/remote.php/dav/files/alice/Docs/a.pdf', '/remote.php/dav/files/[hidden]'];
        yield 'files with a folder where the account goes' => ['/remote.php/dav/files/Confidential/payroll', '/remote.php/dav/files/[hidden]'];
        yield 'uploads with a folder where the account goes' => ['/remote.php/dav/uploads/Confidential/payroll', '/remote.php/dav/uploads/[hidden]'];
        yield 'trashbin with a folder where the account goes' => ['/remote.php/dav/trashbin/Confidential/payroll', '/remote.php/dav/trashbin/[hidden]'];
        yield 'versions with a folder where the account goes' => ['/remote.php/dav/versions/Confidential/payroll', '/remote.php/dav/versions/[hidden]'];
        yield 'legacy webdav' => ['/remote.php/webdav/Confidential/payroll', '/remote.php/webdav/[hidden]'];
        yield 'public share dav' => ['/public.php/dav/files/Confidential/payroll', '/public.php/dav/files/[hidden]'];
        yield 'query' => ['/index.php/apps/files/?dir=/secret', '/index.php/apps/[hidden]?[hidden]'];
        yield 'not a route' => ['https://cloud.example.com/apps/Secret', 'https://cloud.example.com/apps/[hidden]'];
        yield 'ocs with query' => ['/ocs/v2.php/apps/notifications/api/v2/notifications?format=json', '/ocs/v2.php/apps/[hidden]?[hidden]'];
        yield 'app segment in capitals' => ['/apps/Secret/payroll', '/apps/[hidden]'];
        yield 'app segment in lower case' => ['https://cloud.example.com/index.php/apps/secret/payroll', 'https://cloud.example.com/index.php/apps/[hidden]'];
        yield 'real app id' => ['/index.php/apps/files/?dir=/x', '/index.php/apps/[hidden]?[hidden]'];
        yield 'ocs app segment' => ['/ocs/v2.php/apps/secret/payroll', '/ocs/v2.php/apps/[hidden]'];
        yield 'public share' => ['/s/AbC/download', '/s/[hidden]'];
        yield 'route alone' => ['/status.php', '/status.php'];
    }

    /** @dataProvider urls */
    public function testAUrlKeepsHostAndRoutePrefixOnly(string $url, string $expected): void {
        $this->assertSame($expected, LogRedactor::url($url));
    }

    /** With messages hidden, a signature groups by level, app and exception class, and carries no text at all. */
    public function testTheSummaryGroupsByLevelAppAndExceptionClassWithoutText(): void {
        $entry = static fn (string $message, ?string $class, string $app = 'webdav'): \stdClass => json_decode(json_encode(
            ['level' => 3, 'time' => '2026-10-07T08:12:03-03:00', 'app' => $app, 'user' => 'alice', 'url' => '/apps/Secret/x',
                'message' => $message, 'userAgent' => 'curl']
            + ($class === null ? [] : ['exception' => ['Exception' => $class, 'Message' => $message, 'Code' => 0, 'Trace' => []]])));
        $entries = [
            $entry('Cannot access salary-secret.txt', 'OCP\\Files\\NotFoundException'),
            $entry('File with name /Docs/Salary#Confidential could not be located', 'OCP\\Files\\NotFoundException'),
            $entry('Upload of image/salary.pdf failed', null),
            $entry('Report 2026/10 not found', null, 'core'),
        ];
        $time = new LogTime(\DateTimeInterface::ATOM, 'UTC');

        $hidden = (new LogAnalyzer($time, true))->analyze($entries);
        $shown = json_encode((new LogAnalyzer($time))->analyze($entries));

        $this->assertSame([
            ['[hidden]', 'OCP\\Files\\NotFoundException', 'webdav', 2, '[hidden]'],
            ['[hidden]', null, 'core', 1, '[hidden]'],
            ['[hidden]', null, 'webdav', 1, '[hidden]'],
        ], array_map(static fn (array $s): array => [$s['signature'], $s['exception'], $s['app'], $s['count'], $s['example']], $hidden['signatures']));
        $this->assertSame(3, $hidden['distinctSignatures']);
        $this->assertSame([['path' => '/apps/[hidden]', 'count' => 4]], $hidden['topUrls']);
        foreach (['salary', 'Salary', 'Confidential', 'Secret', 'image/', '2026/10', 'Report'] as $part) {
            $this->assertStringNotContainsString($part, json_encode($hidden));
        }
        $this->assertStringContainsString('salary-secret.txt', $shown);
    }

    /** On by default, and only active while files are hidden by tags or the tag setting is unreadable. */
    public function testTheSwitchIsOnByDefaultAndActiveOnlyWithHiddenTags(): void {
        $config = new InMemoryConfig();
        $appConfig = new InMemoryAppConfig();
        $service = fn (): LogFileNames => new LogFileNames($appConfig->worker($this),
            new VisibilityGuard($config->mock($this), $this->createMock(ISystemTagObjectMapper::class)));

        $this->assertTrue($service()->enabled());
        $this->assertFalse($service()->active(), 'Without configured tags nothing changes');

        $config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $this->assertTrue($service()->active());

        $config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"';
        $this->assertTrue($service()->active(), 'Invalid configuration hides everything');

        $service()->setEnabled(false);
        $this->assertFalse($service()->enabled());
        $this->assertFalse($service()->active());
    }

    /**
     * @return iterable<string, array{string, string, string}> method in the log, shown hidden, shown otherwise
     */
    public static function methods(): iterable {
        yield 'custom verb' => ['salary.pdf', '[hidden]', 'salary.pdf'];
        yield 'webdav verb' => ['PROPFIND', 'PROPFIND', 'PROPFIND'];
        yield 'http verb in lower case' => ['get', 'GET', 'get'];
        yield 'calendar verb' => ['MkCalendar', 'MKCALENDAR', 'MkCalendar'];
    }

    /**
     * The method is the client's text too: with messages hidden only a known HTTP or WebDAV verb stays, in capitals.
     *
     * @dataProvider methods
     */
    public function testOnlyAKnownMethodIsShownWithMessagesHidden(string $method, string $hidden, string $shown): void {
        $entry = (object)['level' => 3, 'method' => $method, 'message' => 'x'];

        $this->assertSame($hidden, LogRedactor::entry($entry, true)['method']);
        $this->assertSame($shown, LogRedactor::entry($entry)['method']);
    }
}
