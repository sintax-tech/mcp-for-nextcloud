<?php
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Service\Calendar;

use OCA\Mcp\Service\Calendar\CalendarSelftestService;
use OCA\Mcp\Service\Calendar\CalendarSelftestReader;
use OCA\Mcp\Tests\Unit\Tools\Calendar\CalendarTestCase;
use OCA\Mcp\Tools\Calendar\Calendar;
use OCA\Mcp\Tools\Calendar\CalendarAccess;
use OCA\Mcp\Tools\Calendar\TrashPolicy;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;

final class CalendarSelftestServiceTest extends CalendarTestCase {
    private array $steps = [];
    private array $inbox = [];
    private ?IUser $active = null;

    public function testSuccessfulRunVerifiesFourOperationsOnlyAfterCleanup(): void {
        self::assertTrue($this->service()->run('alice', [], $this->report(...)));
        self::assertSame(['read', 'create', 'edit', 'move', 'delete'], $this->gate->operations());
        self::assertFalse($this->gate->invitationsVerified());
        self::assertSame('OK', $this->steps['cleanup']['status']);
        self::assertNull($this->active);
        foreach ($this->store->calendars[self::ALICE] as $calendar) {
            if (str_starts_with($calendar['uri'], 'mcp-selftest-')) {
                self::assertTrue($calendar['deleted']);
            }
        }
        self::assertFalse($this->store->calendars[self::ALICE][0]['deleted']);
    }

    public function testFailedSuppressionRevokesOldProofAndCleansUp(): void {
        $this->verification = '{"app":"0.6.10","nextcloud":"33.0","operations":["create"]}';
        $service = $this->service();
        $this->dav->failureFor['update'] = new \RuntimeException('private ICS secret');
        self::assertFalse($service->run('alice', [], $this->report(...)));
        self::assertSame(['read'], $this->gate->operations());
        self::assertSame('OK', $this->steps['cleanup']['status']);
        self::assertStringNotContainsString('private ICS secret', json_encode($this->steps));
        self::assertNull($this->active);
    }

    public function testCleanupFailureCannotEnableWrites(): void {
        $service = $this->service();
        $this->dav->failureFor['deleteCalendar'] = new \RuntimeException('cleanup failed');
        self::assertFalse($service->run('alice', [], $this->report(...)));
        self::assertSame(['read'], $this->gate->operations());
        self::assertSame('FALHA', $this->steps['cleanup']['status']);
        self::assertNull($this->active);
    }

    public function testNoEnableRunsTheProofButDoesNotRecordIt(): void {
        self::assertTrue($this->service()->run('alice', ['no-enable' => true], $this->report(...)));
        self::assertSame(['read'], $this->gate->operations());
    }

    public function testDisabledTrashFailsBeforeWriting(): void {
        $this->retention = '0';
        self::assertFalse($this->service()->run('alice', [], $this->report(...)));
        $this->assertNoWrites();
    }

    public function testSharedTransferIsOptionalAndOnlyTouchesCreatedUid(): void {
        self::assertTrue($this->service()->run('alice', ['shared-calendar' => self::TEAM], $this->report(...)));
        self::assertContains('transfer', $this->gate->operations());
        self::assertFalse($this->store->calendars[self::ALICE][2]['deleted']);
    }

    public function testCommandSupportsRevokeWithoutUidAndRejectsMissingUid(): void {
        $command = new \OCA\Mcp\Command\CalendarSelftest($this->service(), $this->gate);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        self::assertSame(2, $tester->execute([]));
        $this->verification = '{"app":"0.6.10","nextcloud":"33.0","operations":["create"]}';
        self::assertSame(0, $tester->execute(['--revoke' => true]));
        self::assertSame(['read'], $this->gate->operations());
        $this->assertNoWrites();
    }

    public function testCommandNoEnableRunsTheServiceAndPrintsEvidence(): void {
        $tester = new \Symfony\Component\Console\Tester\CommandTester(new \OCA\Mcp\Command\CalendarSelftest($this->service(), $this->gate));
        self::assertSame(0, $tester->execute(['uid' => 'alice', '--no-enable' => true]));
        self::assertStringContainsString('OK stale-dav: HTTP 412', $tester->getDisplay());
        self::assertStringContainsString('OK cleanup', $tester->getDisplay());
        self::assertSame(['read'], $this->gate->operations());
    }

    public function testPartialCalendarCreationStillCleansTheCreatedCollection(): void {
        $service = $this->service();
        $this->dav->afterMkcalendar = function (): void { throw new \RuntimeException('post-mutation failure'); };
        self::assertFalse($service->run('alice', [], $this->report(...)));
        foreach ($this->store->calendars[self::ALICE] as $row) {
            if (str_starts_with($row['uri'], 'mcp-selftest-')) { self::assertTrue($row['deleted']); }
        }
        self::assertSame(['read'], $this->gate->operations());
    }

    public function testInvitationProofAndCleanupTouchOnlyTheGeneratedUid(): void {
        $service = $this->service();
        $this->store->addCalendar(self::BOB, 20, 'bob-personal', self::BOB);
        $unrelated = self::ics("UID:unrelated\nDTSTART:20261001T120000Z\nDTEND:20261001T130000Z");
        $this->store->addObject(20, 'unrelated.ics', $unrelated);
        $this->inbox['bob'][] = ['uri' => 'unrelated-itip.ics', 'data' => $unrelated, 'etag' => '"other"'];
        $this->dav->schedule = function (string $ics, string $method): string {
            $cal = \Sabre\VObject\Reader::read($ics);
            $uid = (string)$cal->VEVENT->UID;
            if ($method === 'PUT') {
                $cal->VEVENT->ATTENDEE['SCHEDULE-STATUS'] = '1.2;Message delivered locally';
                $this->store->addObject(20, $uid . '.ics', $cal->serialize());
                $cal->METHOD = 'REQUEST';
            } else { $cal->METHOD = 'CANCEL'; }
            $this->inbox['bob'][] = ['uri' => $uid . '-' . $method . '.ics', 'data' => $cal->serialize(), 'etag' => '"test"'];
            unset($cal->METHOD);
            return $cal->serialize();
        };
        $this->dav->deleteInbox = function (string $userId, string $uri): void {
            $this->inbox[$userId] = array_values(array_filter($this->inbox[$userId], static fn ($row) => $row['uri'] !== $uri));
        };
        self::assertTrue($service->run('alice', ['attendee-uid' => 'bob'], $this->report(...)));
        self::assertTrue($this->gate->invitationsVerified());
        self::assertSame('OK', $this->steps['invitations']['status']);
        self::assertSame(['unrelated-itip.ics'], array_column($this->inbox['bob'], 'uri'));
        self::assertFalse($this->store->objects[20]['unrelated.ics']['deleted']);
        self::assertNull($this->active);
    }

    public function testUnprovedInternalDeliveryCannotOpenInvitationsOrWrites(): void {
        self::assertFalse($this->service()->run('alice', ['attendee-uid' => 'bob'], $this->report(...)));
        self::assertSame(['read'], $this->gate->operations());
        self::assertSame('FALHA', $this->steps['invitations']['status']);
        self::assertSame('OK', $this->steps['cleanup']['status']);
    }

    public function testAclProbeRestoresSessionAndAcceptsOnlyForbiddenOrMissing(): void {
        self::assertTrue($this->service()->run('alice', ['acl-probe-user' => 'bob'], $this->report(...)));
        self::assertSame('OK', $this->steps['acl']['status']);
        self::assertNull($this->active);
    }

    private function report(array $step): void {
        $this->steps[$step['step']] = $step;
    }

    private function service(): CalendarSelftestService {
        $this->dav->selftestMode = true;
        $time = $this->createMock(ITimeFactory::class);
        $time->method('now')->willReturn(new \DateTimeImmutable(self::NOW));
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid): ?IUser {
            if (!isset($this->emails[$uid])) { return null; }
            $u = $this->createMock(IUser::class);
            $u->method('getUID')->willReturn($uid);
            $u->method('isEnabled')->willReturn(true);
            $u->method('getEMailAddress')->willReturn($this->emails[$uid]);
            return $u;
        });
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturnCallback(fn () => $this->active);
        $session->method('setVolatileActiveUser')->willReturnCallback(function (?IUser $u): void { $this->active = $u; });
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn(true);
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(fn () => $this->retention);
        $reader = $this->createMock(CalendarSelftestReader::class);
        $reader->method('calendarState')->willReturnCallback(function (Calendar $cal): array {
            $deleted = false;
            foreach ($this->store->calendars[self::ALICE] as $row) {
                if ($row['id'] === $cal->id) { $deleted = $row['deleted']; }
            }
            return ['syncToken' => (string)count($this->dav->calls), 'deleted' => $deleted];
        });
        $reader->method('schedulingObjects')->willReturnCallback(fn (string $uid): array => $this->inbox[$uid] ?? []);
        return new CalendarSelftestService($this->dav,
            $this->writeHandlers['calendar_create_event'], $this->writeHandlers['calendar_update_event'],
            $this->writeHandlers['calendar_move_event'], $this->writeHandlers['calendar_delete_event'],
            $this->writeHandlers['calendar_transfer_event'], $this->gate,
            new CalendarAccess($this->store), $this->store, $time, $users, $session, $apps,
            new TrashPolicy($config), $reader);
    }
}
