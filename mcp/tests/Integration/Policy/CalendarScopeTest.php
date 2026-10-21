<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Policy;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\Http;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCA\Mcp\Platform\Dav\CalDav\CollectionAccess as CalendarAccess;
use OCA\Mcp\Modules\Calendar\CalendarModule;
use OCA\Mcp\Modules\Tasks\TasksModule;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Server;
use RuntimeException;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV\PropPatch;
use Throwable;

/**
 * B6 on the real server (S7, S15): calendars, task lists, a share and its rename, withdrawal and re-grant all live in
 * the DAV app of the container; the scope is saved by the container's writer, now open for calendar and tasks, which
 * checks every item against the native calendars of alice. Task tools are called over HTTP through the MCP endpoint;
 * the calendar tools need the Calendar app, which the harness provisions, so they run through the container's
 * CalendarModule inside one policy operation, as the endpoint runs them.
 */
final class CalendarScopeTest extends IntegrationTestCase {
    private const HOME = '/remote.php/dav/calendars/' . Fixtures::ALICE . '/';
    private const MISSING = self::HOME . 'mcp-it-missing/';

    private string $run = '';
    /** @var list<int> calendars to delete */
    private array $created = [];
    /** @var array{service:?string, eligible:?string, connected:?string} raw values before the test; null = absent */
    private array $before = [];

    protected function setUp(): void {
        parent::setUp();
        $this->run = bin2hex(random_bytes(4));
        // Other tests read alice's eligibility from groups only, so the individual values set here are put back.
        $apps = Server::get(IAppConfig::class);
        $users = Server::get(IUserConfig::class);
        $this->before = [
            'service' => $apps->hasKey('mcp', GrantPolicy::SERVICE_KEY) ? $apps->getValueString('mcp', GrantPolicy::SERVICE_KEY) : null,
            'eligible' => $users->hasKey(Fixtures::ALICE, 'mcp', GrantPolicy::ELIGIBLE_KEY) ? $users->getValueString(Fixtures::ALICE, 'mcp', GrantPolicy::ELIGIBLE_KEY) : null,
            'connected' => $users->hasKey(Fixtures::ALICE, 'mcp', GrantPolicy::CONNECTED_KEY) ? $users->getValueString(Fixtures::ALICE, 'mcp', GrantPolicy::CONNECTED_KEY) : null,
        ];
        $policy = Server::get(GrantPolicy::class);
        $policy->setGlobalEnabled(true);
        $policy->setEligible(Fixtures::ALICE, true);
        $policy->setConnected(Fixtures::ALICE, true);
    }

    protected function tearDown(): void {
        $db = $this->official();
        foreach ([ResourceScopeMapper::MODES_TABLE, ResourceScopeMapper::ITEMS_TABLE] as $table) {
            $qb = $db->getQueryBuilder();
            $qb->delete($table)->where($qb->expr()->eq('user_key', $qb->createNamedParameter(IdentityKey::user(Fixtures::ALICE))))->executeStatement();
        }
        $config = Server::get(IConfig::class);
        $this->before['service'] === null ? $config->deleteAppValue('mcp', GrantPolicy::SERVICE_KEY) : $config->setAppValue('mcp', GrantPolicy::SERVICE_KEY, $this->before['service']);
        foreach (['eligible' => GrantPolicy::ELIGIBLE_KEY, 'connected' => GrantPolicy::CONNECTED_KEY] as $name => $key) {
            $this->before[$name] === null ? $config->deleteUserValue(Fixtures::ALICE, 'mcp', $key) : $config->setUserValue(Fixtures::ALICE, 'mcp', $key, $this->before[$name]);
        }
        Server::get(IUserConfig::class)->clearCache(Fixtures::ALICE, false);
        Server::get(IAppConfig::class)->clearCache(false);
        foreach ($this->created as $id) {
            try {
                $this->backend()->deleteCalendar($id, true);
            } catch (Throwable) {
                // Already deleted by the test.
            }
        }
        parent::tearDown();
    }

    private function backend(): CalDavBackend {
        return Server::get(CalDavBackend::class);
    }

    /** @param list<string> $components */
    private function calendar(string $owner, string $uri, array $components): int {
        $id = $this->backend()->createCalendar('principals/users/' . $owner, $uri, [
            '{DAV:}displayname' => $uri,
            '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new SupportedCalendarComponentSet($components),
        ]);
        $this->created[] = (int)$id;
        return (int)$id;
    }

    private function task(int $calendarId, string $uid): void {
        $this->backend()->createCalendarObject($calendarId, $uid . '.ics', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//mcp-it//EN\r\n"
            . "BEGIN:VTODO\r\nUID:$uid\r\nDTSTAMP:20261001T100000Z\r\nSUMMARY:Task $uid\r\nEND:VTODO\r\nEND:VCALENDAR\r\n");
    }

    /** bob shares (true) or withdraws (false) his calendar with alice through CalDAV, as the Calendar app does. */
    private function share(string $uri, bool $grant): void {
        $href = '<d:href>principal:principals/users/' . Fixtures::ALICE . '</d:href>';
        $body = '<?xml version="1.0" encoding="utf-8"?><o:share xmlns:d="DAV:" xmlns:o="http://owncloud.org/ns">'
            . ($grant ? "<o:set>$href<o:read-write/></o:set>" : "<o:remove>$href</o:remove>") . '</o:share>';
        $response = Http::request('POST', Http::BASE . '/remote.php/dav/calendars/' . Fixtures::BOB . '/' . $uri . '/', [
            'Authorization: ' . LoginFactory::basic(Fixtures::BOB)->authorization(), 'Content-Type: application/xml; charset=utf-8',
        ], $body);
        if (!in_array($response['status'], [200, 204], true)) {
            throw new RuntimeException("Share of $uri answered HTTP {$response['status']}: " . substr($response['body'], 0, 400));
        }
    }

    /** @param list<int>|null $ids */
    private function scope(string $module, ?array $ids): void {
        Server::get(\OCA\Mcp\Access\ResourceScope\ResourceScopeWriter::class)->setResourceScope(Server::get(PolicyStateMapper::class)->state()['revision'], 'admin',
            Fixtures::ALICE, $module, $ids === null ? null : array_map('strval', $ids));
    }

    /** @return array<string, mixed> what a calendar tool answers alice, in one policy operation like the endpoint */
    private function calendarTool(string $name, array $arguments, bool $preview = false): array {
        return Server::get(GrantPolicy::class)->within(Fixtures::ALICE, function () use ($name, $arguments, $preview): array {
            $module = Server::get(CalendarModule::class);
            try {
                return $preview ? $module->preview($name, $arguments, Fixtures::ALICE) : $module->call($name, $arguments, Fixtures::ALICE);
            } catch (Throwable $e) {
                return ['refused' => $e::class, 'message' => $e->getMessage()];
            }
        });
    }

    /** @return list<string> paths calendar_list_calendars gives alice */
    private function listedCalendars(): array {
        $result = $this->calendarTool('calendar_list_calendars', []);
        $items = $result['structuredContent'] ?? json_decode($result['content'][0]['text'], true);
        return array_column($items['calendars'] ?? $items, 'path');
    }

    private function tasksTool(string $name, array $arguments): array {
        return Server::get(GrantPolicy::class)->within(Fixtures::ALICE, function () use ($name, $arguments): array {
            try {
                return Server::get(TasksModule::class)->call($name, $arguments, Fixtures::ALICE);
            } catch (Throwable $e) {
                return ['refused' => $e::class, 'message' => $e->getMessage()];
            }
        });
    }

    /** @return int the id alice sees for a calendar of bob shared with her, through the native access */
    private function sharedId(string $uri): ?int {
        foreach (Server::get(CalendarAccess::class)->visible(Fixtures::ALICE) as $calendar) {
            if ($calendar->uri === $uri . '_shared_by_' . Fixtures::BOB) {
                return $calendar->id;
            }
        }
        return null;
    }

    public function testS7OnlyCalendarsInsideTheScopeReachTheToolsAndTheOthersAnswerAsMissing(): void {
        $c1 = $this->calendar(Fixtures::ALICE, "mcp-it-c1-{$this->run}", ['VEVENT']);
        $c2 = $this->calendar(Fixtures::ALICE, "mcp-it-c2-{$this->run}", ['VEVENT']);
        $both = $this->calendar(Fixtures::ALICE, "mcp-it-both-{$this->run}", ['VEVENT', 'VTODO']);
        $this->task($both, "t-{$this->run}");
        $c2Path = self::HOME . "mcp-it-c2-{$this->run}/";
        $bothPath = self::HOME . "mcp-it-both-{$this->run}/";

        $this->scope('calendar', [$c1, $both]);
        $this->assertSame([self::HOME . "mcp-it-c1-{$this->run}/", $bothPath], $this->listedCalendars());
        $event = ['summary' => 'x', 'start' => '2026-03-12T15:00:00Z', 'end' => '2026-03-12T16:00:00Z'];
        foreach ([['calendar_list_events', ['from' => '2026-03-12', 'to' => '2026-03-13'], false],
            ['calendar_create_event', $event, true], ['calendar_create_event', $event + ['confirm' => true], false]] as [$name, $arguments, $preview]) {
            $outside = $this->calendarTool($name, ['calendar' => $c2Path] + $arguments, $preview);
            $this->assertSame($this->calendarTool($name, ['calendar' => self::MISSING] + $arguments, $preview), $outside, $name);
        }
        $this->assertSame([], $this->backend()->getCalendarObjects($c2), 'nothing was written outside the scope');

        // Tasks keep their own scope: the calendar holding both components is reachable as a task list...
        $this->scope('tasks', [$both]);
        $mcp = new McpClient(LoginFactory::basic(Fixtures::ALICE));
        $lists = $mcp->tool('tasks_list_calendars');
        $this->assertTrue($lists->ok(), $lists->describe());
        $this->assertSame([$bothPath], array_column($lists->json(), 'path'));
        $read = $mcp->tool('tasks_read_task', ['calendar' => $bothPath, 'uid' => "t-{$this->run}"]);
        $this->assertTrue($read->ok(), $read->describe());
        // ...and leaving it out of the tasks scope hides it there while the calendar scope still holds it.
        $this->scope('tasks', []);
        $this->assertSame([], $mcp->tool('tasks_list_calendars')->json());
        $outside = $mcp->tool('tasks_read_task', ['calendar' => $bothPath, 'uid' => "t-{$this->run}"]);
        $missing = $mcp->tool('tasks_read_task', ['calendar' => self::MISSING, 'uid' => "t-{$this->run}"]);
        $this->assertTrue($outside->isError(), $outside->describe());
        $this->assertSame($missing->fingerprint(), $outside->fingerprint());
        $this->assertSame($this->tasksTool('tasks_create_task', ['calendar' => self::MISSING, 'summary' => 'x', 'confirm' => true]),
            $this->tasksTool('tasks_create_task', ['calendar' => $bothPath, 'summary' => 'x', 'confirm' => true]));
        $this->assertCount(1, $this->backend()->getCalendarObjects($both), 'nothing was written outside the scope');
        $this->assertContains($bothPath, $this->listedCalendars());
    }

    public function testS15TheSharedCalendarIsKeptByTheOwnersIdUntilItIsRecreated(): void {
        $uri = "mcp-it-team-{$this->run}";
        $owner = $this->calendar(Fixtures::BOB, $uri, ['VEVENT', 'VTODO']);
        $this->share($uri, true);
        $sharedPath = self::HOME . $uri . '_shared_by_' . Fixtures::BOB . '/';
        $this->assertSame($owner, $this->sharedId($uri), 'the share carries the id of the owner\'s calendar');

        $this->scope('calendar', [$owner]);
        $this->assertSame([$sharedPath], $this->listedCalendars());

        $patch = new PropPatch(['{DAV:}displayname' => 'Renamed ' . $this->run]);
        $this->backend()->updateCalendar($owner, $patch);
        $patch->commit();
        $this->assertSame([$sharedPath], $this->listedCalendars(), 'a rename keeps the scope');

        $this->share($uri, false);
        $this->assertSame([], $this->listedCalendars(), 'a withdrawn share is gone natively');
        $this->share($uri, true);
        $this->assertSame($owner, $this->sharedId($uri));
        $this->assertSame([$sharedPath], $this->listedCalendars(), 'the share granted again is the same calendar');

        $this->backend()->deleteCalendar($owner, true);
        $recreated = $this->calendar(Fixtures::BOB, $uri, ['VEVENT', 'VTODO']);
        $this->share($uri, true);
        $this->assertNotSame($owner, $recreated);
        $this->assertSame($recreated, $this->sharedId($uri));
        $this->assertSame([], $this->listedCalendars(), 'the recreated calendar does not inherit the old scope');
        $this->assertSame($this->calendarTool('calendar_list_events', ['calendar' => self::MISSING, 'from' => '2026-03-12', 'to' => '2026-03-13']),
            $this->calendarTool('calendar_list_events', ['calendar' => $sharedPath, 'from' => '2026-03-12', 'to' => '2026-03-13']));
    }
}
