<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar\Scheduling;

use OCA\Mcp\Tests\Unit\Tools\Calendar\FakeCalendarStore;
use OCA\Mcp\Tools\Calendar\CalendarAccess;
use OCA\Mcp\Tools\Calendar\Scheduling\SharedCalendarFinder;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class SharedCalendarFinderTest extends TestCase {
    private const ORGANIZER = 'principals/users/alice';

    private FakeCalendarStore $store;
    /** @var array<string, list<string>> group ids by existing user */
    private array $users = ['alice' => [], 'bob' => [], 'carol' => []];
    private SharedCalendarFinder $finder;

    protected function setUp(): void {
        $this->store = new FakeCalendarStore();
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturnCallback(function (string $uid): ?IUser {
            if (!isset($this->users[$uid])) {
                return null;
            }
            $user = $this->createMock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            return $user;
        });
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('getUserGroupIds')->willReturnCallback(fn (IUser $u): array => $this->users[$u->getUID()]);
        $this->finder = new SharedCalendarFinder(new CalendarAccess($this->store), $this->store, $userManager, $groupManager);
    }

    public function testNoAttendeesYieldsNoCandidates(): void {
        $this->store->addCalendar(self::ORGANIZER, 1, 'personal', self::ORGANIZER);
        self::assertSame([], $this->finder->candidates('alice', []));
    }

    public function testNoCalendarSharedWithEveryoneYieldsNothing(): void {
        $this->store->addCalendar(self::ORGANIZER, 1, 'personal', self::ORGANIZER);
        self::assertSame([], $this->finder->candidates('alice', ['bob']));
    }

    public function testCalendarSharedByUserIsTheOnlyCandidate(): void {
        $this->store->addCalendar(self::ORGANIZER, 1, 'personal', self::ORGANIZER, ['name' => 'Personal']);
        $this->store->addCalendar(self::ORGANIZER, 2, 'team', self::ORGANIZER, ['name' => 'Team']);
        $this->store->addShare(2, 'principals/users/bob');
        self::assertSame(
            [['path' => '/remote.php/dav/calendars/alice/team/', 'name' => 'Team', 'ownerId' => 'alice']],
            $this->finder->candidates('alice', ['bob']),
        );
    }

    public function testCalendarSharedByGroupMatchesMembers(): void {
        $this->users['bob'] = ['sales'];
        $this->store->addCalendar(self::ORGANIZER, 2, 'team', self::ORGANIZER, ['name' => 'Team']);
        $this->store->addShare(2, 'principals/groups/sales');
        self::assertCount(1, $this->finder->candidates('alice', ['bob']));
        self::assertSame([], $this->finder->candidates('alice', ['bob', 'carol']));
    }

    public function testUrlEncodedGroupAndUserPrincipalsAreDecoded(): void {
        $this->users['bob'] = ['My Group'];
        $this->users['ana@example.com'] = [];
        $this->store->addCalendar(self::ORGANIZER, 2, 'team', self::ORGANIZER);
        $this->store->addShare(2, 'principals/groups/My%20Group');
        $this->store->addShare(2, 'principals/users/ana%40example.com');
        self::assertCount(1, $this->finder->candidates('alice', ['bob', 'ana@example.com']));
    }

    public function testUrlEncodedUserShareDoesNotMatchAnotherUid(): void {
        $this->users['ana@example.com'] = [];
        $this->store->addCalendar(self::ORGANIZER, 2, 'team', self::ORGANIZER);
        $this->store->addShare(2, 'principals/users/ana%40example.com');
        self::assertSame([], $this->finder->candidates('alice', ['ana%40example.com']));
        self::assertSame([], $this->finder->candidates('alice', ['bob']));
    }

    public function testAllAttendeesMustSeeTheCalendarThroughAnyShare(): void {
        $this->users['carol'] = ['sales'];
        $this->store->addCalendar(self::ORGANIZER, 2, 'team', self::ORGANIZER);
        $this->store->addShare(2, 'principals/users/bob');
        $this->store->addShare(2, 'principals/groups/sales');
        self::assertCount(1, $this->finder->candidates('alice', ['bob', 'carol']));
    }

    public function testOwnerAttendeeSeesACalendarSharedWithTheOrganizer(): void {
        $this->store->addCalendar(self::ORGANIZER, 3, 'team_shared_by_bob', 'principals/users/bob', ['name' => 'Bob team']);
        self::assertSame(
            [['path' => '/remote.php/dav/calendars/alice/team_shared_by_bob/', 'name' => 'Bob team', 'ownerId' => 'bob']],
            $this->finder->candidates('alice', ['bob']),
        );
    }

    public function testOwnerPlusSharedAttendee(): void {
        $this->store->addCalendar(self::ORGANIZER, 3, 'team_shared_by_bob', 'principals/users/bob');
        $this->store->addShare(3, 'principals/users/carol');
        self::assertCount(1, $this->finder->candidates('alice', ['bob', 'carol']));
        self::assertSame([], $this->finder->candidates('alice', ['bob', 'carol', 'zed']));
    }

    public function testReadOnlyCalendarsOfTheOrganizerAreExcluded(): void {
        $this->store->addCalendar(self::ORGANIZER, 4, 'private_shared_by_bob', 'principals/users/bob', ['readOnly' => true]);
        self::assertSame([], $this->finder->candidates('alice', ['bob']));
    }

    public function testNonEventAndTrashedCalendarsAreExcluded(): void {
        $this->store->addCalendar(self::ORGANIZER, 5, 'tasks', self::ORGANIZER, ['components' => ['VTODO']]);
        $this->store->addCalendar(self::ORGANIZER, 6, 'gone', self::ORGANIZER, ['deleted' => true]);
        $this->store->addShare(5, 'principals/users/bob');
        $this->store->addShare(6, 'principals/users/bob');
        self::assertSame([], $this->finder->candidates('alice', ['bob']));
    }

    public function testNonexistentAttendeeIsSatisfiedByNothing(): void {
        $this->store->addCalendar(self::ORGANIZER, 2, 'team', self::ORGANIZER);
        $this->store->addShare(2, 'principals/users/ghost');
        $this->store->addShare(2, 'principals/groups/sales');
        self::assertSame([], $this->finder->candidates('alice', ['ghost']));
    }

    public function testOrganizerAsAttendeeSeesOwnCalendars(): void {
        $this->store->addCalendar(self::ORGANIZER, 1, 'personal', self::ORGANIZER);
        $this->store->addShare(1, 'principals/users/bob');
        self::assertCount(1, $this->finder->candidates('alice', ['alice', 'bob']));
    }

    public function testDuplicateAttendeesAreCollapsed(): void {
        $this->store->addCalendar(self::ORGANIZER, 1, 'personal', self::ORGANIZER);
        $this->store->addShare(1, 'principals/users/bob');
        self::assertCount(1, $this->finder->candidates('alice', ['bob', 'bob']));
    }

    public function testCandidatesAreSortedByNameAndCoverManyCalendars(): void {
        $this->store->addCalendar(self::ORGANIZER, 1, 'z', self::ORGANIZER, ['name' => 'Zeta']);
        $this->store->addCalendar(self::ORGANIZER, 2, 'a', self::ORGANIZER, ['name' => 'Alpha']);
        $this->store->addCalendar(self::ORGANIZER, 3, 'm', self::ORGANIZER, ['name' => 'Mid']);
        foreach ([1, 2] as $id) {
            $this->store->addShare($id, 'principals/users/bob');
        }
        self::assertSame(['Alpha', 'Zeta'], array_column($this->finder->candidates('alice', ['bob']), 'name'));
    }
}
