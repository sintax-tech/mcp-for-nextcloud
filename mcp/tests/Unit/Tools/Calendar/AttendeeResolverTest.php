<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use OCA\Mcp\Tools\Calendar\AttendeeResolver;
use OCA\Mcp\Tools\Calendar\CalendarArgumentException;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class AttendeeResolverTest extends TestCase {
    /** @return void */
    public function testResolvesInternalUidsToTheAccountEmail(): void {
        $attendees = $this->resolver()->resolve(['bob', 'carla'], 'alice');

        self::assertSame(
            [['uid' => 'bob', 'email' => 'bob@example.invalid', 'displayName' => 'Roberto Almeida'],
             ['uid' => 'carla', 'email' => 'carla@example.invalid', 'displayName' => 'Carla Dias']],
            $attendees,
        );
    }

    /** @return void */
    public function testExistingInternalUidsMayContainAtSignsAndSpaces(): void {
        $users = $this->users();
        foreach (['bob@example.invalid', 'Bob Silva'] as $uid) {
            $users[$uid] = $this->user($uid, $uid, 'account-address@example.invalid', true);
        }
        $resolved = $this->resolverWith($users)->resolve(['bob@example.invalid', 'Bob Silva'], 'alice');
        self::assertSame(['bob@example.invalid', 'Bob Silva'], array_column($resolved, 'uid'));
        self::assertSame(['account-address@example.invalid', 'account-address@example.invalid'], array_column($resolved, 'email'));
    }

    /** @return void */
    public function testEveryRefusalNamesTheUidSoTheCallerCanFixIt(): void {
        foreach ([
            'ghost' => 'ghost',
            'disabled' => 'disabled',
            'noemail' => 'noemail',
            'alice' => 'alice',
        ] as $label => $uid) {
            try {
                $this->resolver()->resolve([$uid], 'alice');
                self::fail('Expected the attendee to be refused: ' . $label);
            } catch (CalendarArgumentException $exception) {
                self::assertStringContainsString($uid, $exception->getMessage());
            }
        }
    }

    /** @return void */
    public function testUnknownAndEmailLessAccountsAreIndistinguishable(): void {
        $messages = [];
        foreach (['ghost', 'noemail'] as $uid) {
            try {
                $this->resolver()->resolve([$uid], 'alice');
            } catch (CalendarArgumentException $exception) {
                $messages[$uid] = str_replace($uid, '%s', $exception->getMessage());
            }
        }

        self::assertCount(2, $messages);
        self::assertSame($messages['ghost'], $messages['noemail'], 'the message must not reveal whether the account exists');
    }

    /** @return void */
    public function testTheOrganizerMustHaveAnEmailWhenThereAreAttendees(): void {
        $resolver = $this->resolver(['alice' => null]);

        try {
            $resolver->resolve(['bob'], 'alice');
            self::fail('Expected the organizer without e-mail to be refused.');
        } catch (CalendarArgumentException $exception) {
            self::assertStringContainsString('e-mail', $exception->getMessage());
        }
    }

    /** @return void */
    public function testTheOrganizerNeedsNoEmailWithoutAttendees(): void {
        self::assertSame([], $this->resolver(['alice' => null])->resolve([], 'alice'));
    }

    /** @return void */
    public function testAnEmptyListMeansNoGuests(): void {
        // In update an empty list is how the caller removes every guest, so it is not an error.
        self::assertSame([], $this->resolver()->resolve([], 'alice'));
    }

    /** @return array<string, array{0: list<string>}> */
    public static function invalidLists(): array {
        return [
            'too many' => [array_map(static fn (int $i): string => 'u' . $i, range(1, 51))],
            'repeated' => [['bob', 'bob']],
            'empty uid' => [['']],
            'looks like an email' => [['bob@example.invalid']],
        ];
    }

    /** @dataProvider invalidLists */
    public function testTheListItselfIsValidated(array $uids): void {
        $this->expectException(CalendarArgumentException::class);
        $this->resolver()->resolve($uids, 'alice');
    }

    /** @return void */
    public function testTheAcceptedRangeBoundaryIsFifty(): void {
        $users = $this->users();
        $uids = [];
        for ($i = 1; $i <= AttendeeResolver::MAX_ATTENDEES; $i++) {
            $uid = 'u' . $i;
            $uids[] = $uid;
            $users[$uid] = $this->user($uid, $uid, $uid . '@example.invalid', true);
        }

        $resolved = $this->resolverWith($users)->resolve($uids, 'alice');

        self::assertCount(AttendeeResolver::MAX_ATTENDEES, $resolved);
    }

    /**
     * @param array<string, IUser> $users accounts by uid
     * @return AttendeeResolver
     */
    private function resolverWith(array $users): AttendeeResolver {
        return new AttendeeResolver($this->usersManager($users));
    }

    /**
     * @param array<string, string|null> $overrides email per uid; null means no e-mail at all
     * @return AttendeeResolver
     */
    private function resolver(array $overrides = []): AttendeeResolver {
        return new AttendeeResolver($this->usersManager($this->users($overrides)));
    }

    /**
     * @param array<string, string|null> $overrides email per uid; null means no e-mail at all
     * @return array<string, IUser>
     */
    private function users(array $overrides = []): array {
        $accounts = [
            'alice' => ['Alice Silva', 'alice@example.invalid', true],
            'bob' => ['Roberto Almeida', 'bob@example.invalid', true],
            'carla' => ['Carla Dias', 'carla@example.invalid', true],
            'disabled' => ['Desligada', 'disabled@example.invalid', false],
            'noemail' => ['Sem E-mail', null, true],
        ];
        $users = [];
        foreach ($accounts as $uid => [$name, $email, $enabled]) {
            if (array_key_exists($uid, $overrides)) {
                $email = $overrides[$uid];
            }
            $users[$uid] = $this->user($uid, (string)$name, $email === null ? null : $email, $enabled);
        }
        return $users;
    }

    /**
     * @param array<string, IUser> $users accounts by uid
     * @return IUserManager
     */
    private function usersManager(array $users): IUserManager {
        $manager = $this->createMock(IUserManager::class);
        $manager->method('get')->willReturnCallback(static fn ($uid) => is_string($uid) ? ($users[$uid] ?? null) : null);
        return $manager;
    }

    /**
     * @param string $uid user id
     * @param string|null $displayName display name
     * @param string|null $email e-mail address
     * @param bool $enabled whether the account is enabled
     * @return IUser
     */
    private function user(string $uid, ?string $displayName, ?string $email, bool $enabled): IUser {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('getDisplayName')->willReturn($displayName ?? $uid);
        $user->method('getEMailAddress')->willReturn($email);
        $user->method('isEnabled')->willReturn($enabled);
        return $user;
    }
}