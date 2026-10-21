<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Platform\Dav\CalDav;

use OCA\Mcp\Tests\Unit\Modules\Calendar\ScopedCalendarAccessTest;

use OCA\Mcp\Tests\Support\Dav\ScopeStub;

use OCA\Mcp\Tests\Support\Dav\FakeCalendarStore;

use OCA\Mcp\Platform\Dav\CalDav\CollectionAccess as CalendarAccess;
use OCA\Mcp\Platform\Dav\CalDav\CalDavFailure as CalendarException;
use OCA\Mcp\Platform\Dav\CalDav\ScopedCollectionAccess as ScopedCalendarAccess;
use OCA\Mcp\Modules\Calendar\Scheduling\SharedCalendarFinder;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * The tools' calendar access (contract B.2/B.5): S1 (no scope, the native answers unchanged), the identity of a
 * calendar in the scope (S15: the owner's calendar id, kept through a rename and a new share, lost when the calendar is
 * recreated) and the scope applied before the write check. The real-instance half of S15 is in the integration suite.
 */
final class ScopedCollectionAccessTest extends TestCase {
    private const ALICE = 'principals/users/alice';
    private const BOB = 'principals/users/bob';
    private const SHARED = '/remote.php/dav/calendars/alice/team_shared_by_bob/';

    private FakeCalendarStore $store;
    private ScopeStub $scope;
    private ScopedCalendarAccess $access;

    protected function setUp(): void {
        $this->store = new FakeCalendarStore();
        $this->store->addCalendar(self::ALICE, 1, 'personal', self::ALICE, ['name' => 'Personal']);
        $this->store->addCalendar(self::ALICE, 2, 'team_shared_by_bob', self::BOB, ['name' => 'Team']);
        $this->store->addCalendar(self::ALICE, 3, 'readonly_shared_by_bob', self::BOB, ['readOnly' => true]);
        $this->scope = new ScopeStub();
        $this->access = ScopeStub::access($this->store, $this->scope);
    }

    public function testS1WithoutAScopeTheNativeAnswersAreUnchanged(): void {
        $native = new CalendarAccess($this->store);
        foreach (['VEVENT', 'VTODO'] as $component) {
            $this->assertEquals($native->visible('alice', $component), $this->access->visible('alice', $component));
            $this->assertEquals($native->resolve('alice', self::SHARED, $component), $this->access->resolve('alice', self::SHARED, $component));
            $this->assertEquals($native->resolveWritable('alice', self::SHARED, $component), $this->access->resolveWritable('alice', self::SHARED, $component));
        }
    }

    public function testS15TheSharedCalendarIsInTheScopeByTheOwnersId(): void {
        $this->scope->restrict('calendar', ['2']);
        $this->assertSame(['Team'], array_map(static fn ($c): string => $c->name, $this->access->visible('alice')));

        // Renaming changes the display name and nothing else the scope keys on.
        $this->store->calendars[self::ALICE][1]['displayName'] = 'Renamed';
        $this->assertSame(2, $this->access->resolve('alice', self::SHARED)->id);

        // Unsharing hides it natively; sharing it again brings the same calendar id, and the scope applies again.
        $row = $this->store->calendars[self::ALICE][1];
        unset($this->store->calendars[self::ALICE][1]);
        $this->assertSame([], $this->access->visible('alice'));
        $this->store->calendars[self::ALICE][] = $row;
        $this->assertSame(2, $this->access->resolve('alice', self::SHARED)->id);

        // Deleting and recreating it under the same URI makes a new calendar id: the old scope does not follow it.
        $this->store->calendars[self::ALICE] = array_values(array_filter($this->store->calendars[self::ALICE], static fn (array $c): bool => $c['id'] !== 2));
        $this->store->addCalendar(self::ALICE, 9, 'team_shared_by_bob', self::BOB, ['name' => 'Team']);
        $this->assertSame([], $this->access->visible('alice'));
        $this->expectExceptionObject(CalendarException::notFound());
        $this->access->resolve('alice', self::SHARED);
    }

    public function testTheScopeComesBeforeTheWriteCheck(): void {
        $this->scope->restrict('calendar', ['1']);
        $path = '/remote.php/dav/calendars/alice/readonly_shared_by_bob/';
        try {
            $this->access->resolveWritable('alice', $path);
            $this->fail('Read-only calendar outside the scope resolved');
        } catch (CalendarException $e) {
            $this->assertSame(CalendarException::notFound()->getMessage(), $e->getMessage());
        }
        $this->scope->restrict('calendar', ['3']);
        $this->expectExceptionObject(CalendarException::forbidden());
        $this->access->resolveWritable('alice', $path);
    }

    public function testAComponentWithoutAScopeModuleIsAProgrammingError(): void {
        $this->expectException(\LogicException::class);
        $this->access->visible('alice', 'VJOURNAL');
    }

    /** The calendars a plan suggests to share with the guests come from the scoped list too. */
    public function testSharedCalendarSuggestionsStayInsideTheScope(): void {
        $this->store->addShare(2, 'principals/users/bob');
        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(fn (string $uid) => $this->createMock(IUser::class));
        $finder = new SharedCalendarFinder($this->access, $this->store, $users, $this->createMock(IGroupManager::class));

        $this->assertSame(['Team'], array_column($finder->candidates('alice', ['bob']), 'name'));
        $this->scope->restrict('calendar', ['1']);
        $this->assertSame([], $finder->candidates('alice', ['bob']));
    }
}
