<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use OCA\Mcp\Tools\Calendar\CalendarException;
use OCA\Mcp\Tools\Calendar\CapturingSapi;
use OCA\Mcp\Tools\Calendar\DavPathBuilder;
use OCA\Mcp\Tools\Calendar\EmbeddedDavDispatcher;
use OCA\Mcp\Tools\Calendar\Session;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sabre\CalDAV\Backend\SyncSupport;
use Sabre\CalDAV\CalendarHome;
use Sabre\CalDAV\Plugin as CalDavPlugin;
use Sabre\CalDAV\Schedule\Plugin as SchedulePlugin;
use Sabre\DAV\Auth\Plugin as AuthPlugin;
use Sabre\DAV\Server;
use Sabre\DAV\SimpleCollection;
use Sabre\DAVACL\Plugin as AclPlugin;
use Sabre\DAVACL\PrincipalCollection;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;

/**
 * Covers the dispatcher against a real Sabre\DAV\Server (sabre/dav 4.7.0, the version the
 * Nextcloud 33 3rdparty embeds) carrying the plugins that decide a calendar write: core, CalDAV,
 * ACL and Schedule. Nothing here is a copy of the pipeline: the requests really go through Sabre.
 *
 * What this proves: request shaping, path building, principal pinning, the If-Match / If-None-Match /
 * Overwrite preconditions, that the scheduling header lands both on the request the plugins receive
 * and on `server->httpRequest` (the two places the Nextcloud Schedule plugin reads it), that the
 * response stays contained, and the mapping of every refusal.
 *
 * What this cannot prove: the Nextcloud plugins themselves (CustomPrincipalPlugin, DavAclPlugin,
 * OCA\DAV\CalDAV\Schedule\Plugin, IMipPlugin) and the CalDavBackend. Those need a real server and
 * are what `occ mcp:calendar-selftest` exercises.
 */
final class EmbeddedDavDispatcherTest extends TestCase {
    private const ALICE = 'principals/users/alice';
    private const BOB = 'principals/users/bob';
    private const BASE = '/remote.php/dav/';

    private MemoryCalDavBackend $backend;
    private EmbeddedDavDispatcher $dispatcher;
    private Server $server;
    private int $personalId;
    private int $workId;
    /** @var Request|null the request of the last observed dispatch */
    private ?Request $observed = null;

    /** @return void */
    protected function setUp(): void {
        $this->observed = null;
        $this->backend = new MemoryCalDavBackend();
        $this->personalId = $this->backend->addCalendar(self::ALICE, 'personal');
        $this->workId = $this->backend->addCalendar(self::ALICE, 'work');
        $this->dispatcher = $this->dispatcherFor('alice');
        $this->server = $this->serverFor(self::ALICE);
    }

    /** @return void */
    public function testPutCreatesThroughTheServerAndReports201WithTheEtag(): void {
        $result = $this->dispatcher->put('alice', 'personal', 'new.ics', $this->ics('new'));

        self::assertSame(201, $result->status);
        self::assertContains('createCalendarObject', $this->backend->calls);
        self::assertArrayHasKey('new.ics', $this->backend->objects[$this->personalId]);
        self::assertSame($this->backend->objects[$this->personalId]['new.ics']['etag'], $result->etag);
    }

    /** @return void */
    public function testPutSendsIfNoneMatchSoItNeverOverwritesAnExistingObject(): void {
        $this->watchNextRequest();
        $this->backend->addObject($this->personalId, 'taken.ics', $this->ics('taken'));

        try {
            $this->dispatcher->put('alice', 'personal', 'taken.ics', $this->ics('other'));
            self::fail('Expected the If-None-Match precondition to fail.');
        } catch (CalendarException $exception) {
            self::assertStringContainsString('Conflito', $exception->getMessage());
        }

        self::assertSame('*', $this->observed?->getHeader('If-None-Match'));
        self::assertStringContainsString('taken', $this->backend->objects[$this->personalId]['taken.ics']['calendardata']);
    }

    /** @return void */
    public function testUpdateSendsIfMatchAndAStaleEtagChangesNothing(): void {
        $etag = $this->backend->addObject($this->personalId, 'e.ics', $this->ics('e'));
        $this->watchNextRequest();

        $result = $this->dispatcher->update('alice', 'personal', 'e.ics', $etag, $this->ics('edited'));

        self::assertSame($etag, $this->observed?->getHeader('If-Match'));
        self::assertSame(204, $result->status);
        self::assertStringContainsString('edited', $this->backend->objects[$this->personalId]['e.ics']['calendardata']);

        try {
            $this->dispatcher->update('alice', 'personal', 'e.ics', '"stale"', $this->ics('again'));
            self::fail('Expected a stale If-Match to be refused.');
        } catch (CalendarException $exception) {
            self::assertStringContainsString('alterado por outra pessoa', $exception->getMessage());
        }

        self::assertStringContainsString('edited', $this->backend->objects[$this->personalId]['e.ics']['calendardata']);
    }

    /** @return void */
    public function testSchedulingSuppressionHeaderReachesBothPlacesTheNextcloudSchedulePluginReadsIt(): void {
        $this->watchNextRequest();

        $this->dispatcher->put('alice', 'personal', 'quiet.ics', $this->ics('quiet'), false);

        self::assertSame('false', $this->observed?->getHeader('x-nc-scheduling'));
        // The Nextcloud plugin reads beforeUnbind from the server, not from the event argument.
        self::assertSame('false', $this->server->httpRequest->getHeader('x-nc-scheduling'));
        self::assertSame($this->observed, $this->server->httpRequest);
    }

    /** @return void */
    public function testSchedulingHeaderIsAbsentWhenInvitationsAreRequested(): void {
        $this->watchNextRequest();

        $this->dispatcher->put('alice', 'personal', 'loud.ics', $this->ics('loud'), true);

        self::assertNull($this->observed?->getHeader('x-nc-scheduling'));
        self::assertSame(201, $this->server->httpResponse->getStatus());
    }

    /** @return void */
    public function testDeleteRemovesTheObjectThroughTheServer(): void {
        $this->backend->addObject($this->personalId, 'gone.ics', $this->ics('gone'));

        $result = $this->dispatcher->delete('alice', 'personal', 'gone.ics');

        self::assertSame(204, $result->status);
        self::assertArrayNotHasKey('gone.ics', $this->backend->objects[$this->personalId]);
    }

    /** @return void */
    public function testDeleteOnAMissingObjectIsNotFound(): void {
        $this->expectException(CalendarException::class);
        $this->dispatcher->delete('alice', 'personal', 'absent.ics');
    }

    /** @return void */
    public function testMoveKeepsTheUriAndRefusesToOverwriteAnOccupiedDestination(): void {
        $this->backend->addObject($this->personalId, 'm.ics', $this->ics('m'));

        $result = $this->dispatcher->move('alice', 'personal', 'm.ics', 'work');

        self::assertSame(201, $result->status);
        self::assertArrayNotHasKey('m.ics', $this->backend->objects[$this->personalId]);
        self::assertArrayHasKey('m.ics', $this->backend->objects[$this->workId]);

        $this->backend->addObject($this->personalId, 'blocker.ics', $this->ics('blocker'));
        $this->backend->addObject($this->workId, 'blocker.ics', $this->ics('occupied'));

        try {
            $this->dispatcher->move('alice', 'personal', 'blocker.ics', 'work');
            self::fail('Expected Overwrite: F to refuse the occupied destination.');
        } catch (CalendarException $exception) {
            self::assertStringContainsString('Conflito', $exception->getMessage());
        }

        self::assertStringContainsString('occupied', $this->backend->objects[$this->workId]['blocker.ics']['calendardata']);
        self::assertArrayHasKey('blocker.ics', $this->backend->objects[$this->personalId]);
    }

    /** @return void */
    public function testMoveDestinationStaysInsideTheBaseUri(): void {
        $this->watchNextRequest();
        $this->backend->addObject($this->personalId, 'd.ics', $this->ics('d'));

        $this->dispatcher->move('alice', 'personal', 'd.ics', 'work');

        self::assertSame(self::BASE . 'calendars/alice/work/d.ics', $this->observed?->getHeader('Destination'));
        self::assertSame('F', $this->observed?->getHeader('Overwrite'));
    }

    /** @return void */
    public function testWriteOnACalendarSharedReadOnlyIsRefusedByTheAcl(): void {
        $foreign = $this->backend->addCalendar(self::ALICE, 'team');
        $this->backend->share($foreign, self::BOB, true);
        $dispatcher = new EmbeddedDavDispatcher(
            fn () => $this->serverFor(self::ALICE, 'bob'),
            new Session($this->sessionFor('bob')),
            $this->configFor(''),
            new NullLogger(),
        );

        try {
            $dispatcher->put('bob', 'team_shared_by_alice', 'x.ics', $this->ics('x'));
            self::fail('Expected the ACL to refuse the write.');
        } catch (CalendarException $exception) {
            self::assertStringContainsString('Sem permissão', $exception->getMessage());
        }

        self::assertArrayNotHasKey('x.ics', $this->backend->objects[$foreign] ?? []);
    }

    /** @return void */
    public function testWriteOnACalendarSharedForWritingGoesThrough(): void {
        $foreign = $this->backend->addCalendar(self::ALICE, 'team');
        $this->backend->share($foreign, self::BOB, false);
        $dispatcher = new EmbeddedDavDispatcher(
            fn () => $this->serverFor(self::ALICE, 'bob'),
            new Session($this->sessionFor('bob')),
            $this->configFor(''),
            new NullLogger(),
        );

        $result = $dispatcher->put('bob', 'team_shared_by_alice', 'x.ics', $this->ics('x'));

        self::assertSame(201, $result->status);
        self::assertArrayHasKey('x.ics', $this->backend->objects[$foreign]);
    }

    /** @return void */
    public function testPrincipalIsPinnedToTheAuthenticatedUser(): void {
        $this->watchNextRequest();

        $this->dispatcher->put('alice', 'personal', 'p.ics', $this->ics('p'));

        self::assertSame(self::ALICE, $this->server->getPlugin('auth')->getCurrentPrincipal());
    }

    /** @return void */
    public function testTheSessionMustBelongToTheUserBeingWrittenAs(): void {
        $mismatched = new EmbeddedDavDispatcher(
            fn () => $this->serverFor(self::ALICE),
            new Session($this->sessionFor('bob')),
            $this->configFor(''),
            new NullLogger(),
        );
        $calls = count($this->backend->calls);

        try {
            $mismatched->put('alice', 'personal', 'q.ics', $this->ics('q'));
            self::fail('Expected the session mismatch to be refused.');
        } catch (\RuntimeException $exception) {
            self::assertStringNotContainsString('alice', $exception->getMessage());
        }

        self::assertCount($calls, $this->backend->calls);
    }

    /** @return void */
    public function testDispatcherRefusesAServerWithoutTheCustomPrincipalPlugin(): void {
        $public = new EmbeddedDavDispatcher(
            fn () => $this->serverFor(self::ALICE, 'alice', publicPrincipal: true),
            new Session($this->sessionFor('alice')),
            $this->configFor(''),
            new NullLogger(),
        );

        $this->expectException(\RuntimeException::class);
        $public->put('alice', 'personal', 'r.ics', $this->ics('r'));
    }

    /** @return void */
    public function testOversizedIcsIsRefusedBeforeAnyDispatch(): void {
        $calls = count($this->backend->calls);

        try {
            $this->dispatcher->put('alice', 'personal', 'big.ics', str_repeat('x', 200), false, 100);
            self::fail('Expected the size limit to refuse the write.');
        } catch (CalendarException $exception) {
            self::assertStringContainsString('limite', strtolower($exception->getMessage()));
        }

        self::assertCount($calls, $this->backend->calls);
    }

    /** @return void */
    public function testSizeLimitComesFromTheDavAppConfigWhenTheCallerDoesNotPassOne(): void {
        $dispatcher = new EmbeddedDavDispatcher(
            fn () => $this->serverFor(self::ALICE),
            new Session($this->sessionFor('alice')),
            $this->configFor('10'),
            new NullLogger(),
        );

        $this->expectException(CalendarException::class);
        $dispatcher->put('alice', 'personal', 'big.ics', str_repeat('x', 200));
    }

    /** @return void */
    public function testEveryDispatchProducesNoPhpOutputAndNoHeaders(): void {
        $headersBefore = headers_list();
        $level = ob_get_level();
        ob_start();

        try {
            $this->dispatcher->put('alice', 'personal', 'quiet.ics', $this->ics('quiet'));
        } finally {
            $output = ob_get_level() > $level ? (string)ob_get_clean() : '';
        }

        self::assertSame('', $output);
        self::assertSame($headersBefore, headers_list());
        // A write never reaches the fallback sendResponse; only a conditional short circuit does.
        self::assertSame(0, CapturingSapi::$sendCount);
    }

    /** @return void */
    public function testAConditionalShortCircuitIsCapturedInsteadOfEmitted(): void {
        $etag = $this->backend->addObject($this->personalId, 'c.ics', $this->ics('c'));
        // A GET whose If-None-Match matches is the only case where Sabre answers through the SAPI
        // even with sendResponse=false (sabre/dav/lib/DAV/Server.php:466-469). The dispatcher never
        // sends a GET, so this drives the same alignment directly to prove the SAPI holds.
        $server = $this->serverFor(self::ALICE);
        $server->getPlugin('auth')->setCurrentPrincipal(self::ALICE);
        $request = new Request('GET', self::BASE . 'calendars/alice/personal/c.ics', ['If-None-Match' => $etag]);
        $request->setBaseUrl(self::BASE);
        $response = new Response();
        $server->httpRequest = $request;
        $server->httpResponse = $response;
        CapturingSapi::reset();
        $server->sapi = new CapturingSapi();
        $headersBefore = headers_list();
        $level = ob_get_level();
        ob_start();

        try {
            $server->invokeMethod($request, $response, false);
        } finally {
            $output = ob_get_level() > $level ? (string)ob_get_clean() : '';
        }

        self::assertSame('', $output);
        self::assertSame($headersBefore, headers_list());
        self::assertSame(1, CapturingSapi::$sendCount);
        self::assertSame(304, CapturingSapi::$lastStatus);
        self::assertSame(304, $response->getStatus());
    }

    /** @return void */
    public function testAnUnexpectedStatusIsNeverReportedAsSuccess(): void {
        $this->expectException(\RuntimeException::class);
        $this->dispatcher->put('alice', 'personal', 'surprise.ics', $this->ics('s'), false, 10485760, [204]);
    }

    /** @return void */
    public function testEveryOperationGetsItsOwnServerSoPluginStateNeverLeaks(): void {
        $seen = [];
        $dispatcher = new EmbeddedDavDispatcher(
            function () use (&$seen): Server {
                $server = $this->serverFor(self::ALICE);
                $seen[] = $server;
                return $server;
            },
            new Session($this->sessionFor('alice')),
            $this->configFor(''),
            new NullLogger(),
        );

        $dispatcher->put('alice', 'personal', 'a.ics', $this->ics('a'));
        $dispatcher->put('alice', 'personal', 'b.ics', $this->ics('b'));

        self::assertCount(2, $seen);
        self::assertNotSame($seen[0], $seen[1]);
    }

    /** @return void */
    public function testMkcalendarAndItsCleanupGoThroughTheSameDispatcher(): void {
        $created = $this->dispatcher->mkcalendar('alice', 'mcp-selftest-a', 'selftest');

        self::assertSame(201, $created->status);
        self::assertContains('createCalendar', $this->backend->calls);
        self::assertContains('mcp-selftest-a', array_column($this->backend->calendars, 'uri'));

        $removed = $this->dispatcher->deleteCalendar('alice', 'mcp-selftest-a');

        self::assertSame(204, $removed->status);
    }

    /** @return void */
    public function testPathBuilderRefusesSegmentsThatCouldEscapeTheCalendarHome(): void {
        foreach (['../other', 'a/b', 'a\\b', '', '.', '..', "nul\0byte"] as $segment) {
            try {
                DavPathBuilder::objectPath('alice', 'personal', $segment);
                self::fail('Expected the segment to be refused: ' . var_export($segment, true));
            } catch (CalendarException $exception) {
                self::assertStringContainsString('objeto', $exception->getMessage());
            }
        }
    }

    /** @return void */
    public function testPathBuilderEncodesEachSegmentOnItsOwn(): void {
        self::assertSame('calendars/alice/personal/e.ics', DavPathBuilder::objectPath('alice', 'personal', 'e.ics'));
        self::assertSame('calendars/a%20lice/equipe%20nova/reuni%C3%A3o.ics', DavPathBuilder::objectPath('a lice', 'equipe nova', 'reunião.ics'));
        self::assertSame('calendars/alice/personal', DavPathBuilder::calendarPath('alice', 'personal'));
    }

    /**
     * Points the dispatcher at a server that records the request of every method it dispatches.
     *
     * @return void
     */
    private function watchNextRequest(): void {
        $server = $this->serverFor(self::ALICE);
        $server->on('beforeMethod:*', function (Request $request): void {
            $this->observed = $request;
        });
        $this->dispatcher = new EmbeddedDavDispatcher(
            fn () => $server,
            new Session($this->sessionFor('alice')),
            $this->configFor(''),
            new NullLogger(),
        );
        $this->server = $server;
    }

    /**
     * @param string $principal owner principal of the calendar home that is mounted
     * @param string $viewer UID whose home is mounted, when it differs from the owner
     * @param bool $publicPrincipal true to build the server with the public principal plugin
     * @return Server Sabre server with the plugins that decide a calendar write
     */
    private function serverFor(string $principal, string $viewer = '', bool $publicPrincipal = false): Server {
        // CalendarHome::getName() is the last segment of the principal URI, so it is mounted
        // directly under calendars/, exactly as the Nextcloud root collection does.
        $uid = $viewer === '' ? substr($principal, strlen('principals/users/')) : $viewer;
        $home = new CalendarHome($this->backend, ['id' => 1, 'uri' => 'principals/users/' . $uid]);
        $principals = new MemoryPrincipalBackend();
        $principals->addUser($uid, $uid . '@example.invalid');
        // The Sabre ACL plugin resolves the acting principal's groups on every privilege check
        // (sabre/dav/lib/DAVACL/Plugin.php:325-345), so principals/users/<uid> has to exist for the
        // tree to answer at all. The layout mirrors the Nextcloud one: principals/{users,groups}/<uid>.
        $tree = new SimpleCollection('root', [
            new SimpleCollection('calendars', [$home]),
            new SimpleCollection('principals', [new PrincipalCollection($principals, 'principals/users')]),
        ]);
        $server = new Server($tree, new NullSapi());
        $server->setBaseUri(self::BASE);
        $server->addPlugin($publicPrincipal ? new PublicPrincipalFixture() : new CustomPrincipalFixture());
        $acl = new AclPlugin();
        $acl->principalCollectionSet = ['principals/users', 'principals/groups'];
        $server->addPlugin($acl);
        $server->addPlugin(new CalDavPlugin());
        $server->addPlugin(new SchedulePlugin());
        return $server;
    }

    /**
     * @param string $uid UID the session reports
     * @return IUserSession
     */
    private function sessionFor(string $uid): IUserSession {
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturnCallback(function () use ($uid): IUser {
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            return $user;
        });
        return $session;
    }

    /**
     * @param string $eventSizeLimit value returned for dav/event_size_limit
     * @return IConfig
     */
    private function configFor(string $eventSizeLimit): IConfig {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static fn (string $app, string $key, string $default = ''): string => $app === 'dav' && $key === 'event_size_limit' ? $eventSizeLimit : $default,
        );
        return $config;
    }

    /**
     * @param string $uid authenticated UID
     * @return EmbeddedDavDispatcher
     */
    private function dispatcherFor(string $uid): EmbeddedDavDispatcher {
        return new EmbeddedDavDispatcher(
            fn () => $this->serverFor('principals/users/' . $uid),
            new Session($this->sessionFor($uid)),
            $this->configFor(''),
            new NullLogger(),
        );
    }

    /**
     * @param string $summary event summary, also used as the UID
     * @return string iCalendar payload with CRLF line endings
     */
    private function ics(string $summary): string {
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//test//EN', 'BEGIN:VEVENT',
            'UID:' . $summary, 'SUMMARY:' . $summary,
            'DTSTAMP:20260310T115900Z', 'DTSTART:20260310T120000Z', 'DTEND:20260310T130000Z',
            'END:VEVENT', 'END:VCALENDAR', '',
        ];
        return implode("\r\n", $lines);
    }
}

/**
 * Never emits anything, even when Sabre falls back to the SAPI on a conditional short circuit.
 */
final class NullSapi extends \Sabre\HTTP\Sapi {
    /**
     * @param \Sabre\HTTP\ResponseInterface $response response Sabre would otherwise emit
     * @return void
     */
    public static function sendResponse(\Sabre\HTTP\ResponseInterface $response): void {
        throw new \LogicException('The server SAPI must not be used; the dispatcher installs its own.');
    }
}

/**
 * Mirrors the one setter stable33's CustomPrincipalPlugin adds to the Sabre auth plugin
 * (apps/dav/lib/CalDAV/Auth/CustomPrincipalPlugin.php:17-20), without depending on the DAV app.
 */
final class CustomPrincipalFixture extends AuthPlugin {
    /**
     * @param string|null $currentPrincipal principal URI to act as
     * @return void
     */
    public function setCurrentPrincipal(?string $currentPrincipal): void {
        $this->currentPrincipal = $currentPrincipal;
    }
}

/**
 * Mirrors stable33's PublicPrincipalPlugin, which hardcodes the principal and takes no setter
 * (apps/dav/lib/CalDAV/Auth/PublicPrincipalPlugin.php:17-19). The dispatcher must refuse it.
 */
final class PublicPrincipalFixture extends AuthPlugin {
    /** @return string|null */
    public function getCurrentPrincipal(): ?string {
        return 'principals/system/public';
    }
}