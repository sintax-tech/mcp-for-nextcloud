<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Talk;

use InvalidArgumentException;
use OCA\Mcp\Tools\Talk\ConversationAccessException;
use OCA\Mcp\Tools\Talk\Messages;
use OCA\Mcp\Tools\Talk\TalkServices;
use OCA\Mcp\Tools\Talk\TalkUnavailableException;
use OCA\Mcp\Tools\Talk\UserConversationResolver;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class UserConversationResolverTest extends TestCase {
    private TalkServices&MockObject $talkServices;
    private IUserManager&MockObject $userManager;
    private IShareManager&MockObject $shareManager;
    private UserConversationResolver $resolver;

    protected function setUp(): void {
        parent::setUp();
        $this->talkServices = $this->createMock(TalkServices::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->shareManager = $this->createMock(IShareManager::class);
        $this->resolver = new UserConversationResolver($this->talkServices, $this->userManager, $this->shareManager);
    }

    private function givenAccount(string $uid, string $displayName): IUser&MockObject {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('getDisplayName')->willReturn($displayName);

        return $user;
    }

    /** Both accounts exist and the caller may talk to the target, which is the normal case. */
    private function givenReachableTarget(string $targetId = 'bob', string $displayName = 'Bob Souza'): void {
        $accounts = ['alice' => $this->givenAccount('alice', 'Alice Souza'), $targetId => $this->givenAccount($targetId, $displayName)];
        $this->userManager->method('get')->willReturnCallback(
            fn (string $uid): ?IUser => $accounts[$uid] ?? null,
        );
        $this->userManager->method('getDisplayName')->willReturnCallback(
            static fn (string $uid): ?string => $uid === $targetId ? $displayName : null,
        );
        $this->shareManager->method('currentUserCanEnumerateTargetUser')->willReturn(true);
    }

    /** The Talk constants the writability check reads, with the values the product ships today. */
    private function givenConstants(): void {
        $this->talkServices->method('conversationConstants')->willReturn([
            'chatPermission' => 128,
            'lobbyIgnorePermission' => 256,
            'readOnly' => 1,
            'changelogType' => 3,
            'lobbyNone' => 0,
            'actorUsers' => 'users',
        ]);
    }

    private function givenRoomService(DirectRoomService $roomService): void {
        $this->talkServices->method('roomService')->willReturn($roomService);
    }

    private function givenParticipant(int $permissions): void {
        $this->talkServices->method('participantService')->willReturn(new DirectParticipantService($permissions));
    }

    public function testTheTargetIsNamedTheWayTheUserKnowsIt(): void {
        $this->givenReachableTarget();

        $contact = $this->resolver->target('alice', 'bob');

        $this->assertSame('bob', $contact->id);
        $this->assertSame('Bob Souza', $contact->displayName);
        $this->assertSame(['id' => 'bob', 'displayName' => 'Bob Souza'], $contact->describe());
    }

    public function testLookingUpATargetReadsNothingAndCreatesNothing(): void {
        $this->givenReachableTarget();
        // A draft must not leave an empty conversation behind, so no Talk service is touched at all here.
        $this->talkServices->expects($this->never())->method('roomService');
        $this->talkServices->expects($this->never())->method('participantService');

        $this->resolver->target('alice', 'bob');
    }

    public function testAMissingAccountIsRefusedTheSameWayAsOneOutOfReach(): void {
        $alice = $this->givenAccount('alice', 'Alice Souza');
        $this->userManager->method('get')->willReturnCallback(
            static fn (string $uid): ?IUser => $uid === 'alice' ? $alice : null,
        );
        // An account that does not exist cannot be enumerated, and must stay indistinguishable from a hidden one.
        $this->shareManager->method('currentUserCanEnumerateTargetUser')->willReturn(false);

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(Messages::userNotReachable());
        $this->resolver->target('alice', 'ghost');
    }

    public function testWritingToThemselfIsRefusedWithoutLookingUpAPermission(): void {
        $this->givenReachableTarget('alice', 'Alice Souza');
        $this->shareManager->expects($this->never())->method('currentUserCanEnumerateTargetUser');

        try {
            $this->resolver->target('alice', 'alice');
            $this->fail('the caller was allowed to write to themself');
        } catch (ConversationAccessException $e) {
            $this->assertSame(Messages::userNotReachable(), $e->getMessage());
        }
    }

    public function testATargetTheCallerCannotEnumerateIsRefused(): void {
        $accounts = ['alice' => $this->givenAccount('alice', 'Alice'), 'bob' => $this->givenAccount('bob', 'Nome')];
        $this->userManager->method('get')->willReturnCallback(
            static fn (string $uid): ?IUser => $accounts[$uid] ?? null,
        );
        $this->shareManager->method('currentUserCanEnumerateTargetUser')->willReturn(false);

        try {
            $this->resolver->target('alice', 'bob');
            $this->fail('a target out of reach was accepted');
        } catch (ConversationAccessException $e) {
            $this->assertSame(Messages::userNotReachable(), $e->getMessage());
        }
    }

    public function testAGuestListIsResolvedInOrderWithoutRepeatingAnyone(): void {
        $this->givenReachableTarget();

        $contacts = $this->resolver->contacts('alice', ['bob', 'bob'], true);

        $this->assertSame([['id' => 'bob', 'displayName' => 'Bob Souza']], array_map(
            static fn ($contact): array => $contact->describe(),
            $contacts,
        ));
    }

    public function testARefusalInAGuestListNamesTheIdTheCallerSent(): void {
        $this->givenReachableTarget();

        try {
            $this->resolver->contacts('alice', ['bob', 'ghost'], true);
            $this->fail('an account out of reach was accepted in a guest list');
        } catch (ConversationAccessException $e) {
            // The id is the caller's own input, so naming it tells which guest to fix and maps nothing new.
            $this->assertSame(Messages::participantNotReachable('ghost'), $e->getMessage());
        }
    }

    public function testAMalformedAccountIdIsAClientMistake(): void {
        foreach (['', 'a/b', 'a\\b', "bob\0", "bob\n", str_repeat('a', 65)] as $targetId) {
            try {
                $this->resolver->target('alice', $targetId);
                $this->fail('accepted ' . var_export($targetId, true));
            } catch (InvalidArgumentException $e) {
                $this->assertSame(Messages::invalidUser(), $e->getMessage());
            }
        }
    }

    public function testTheConversationComesFromTheProductWithBothAccountsAsCallers(): void {
        $this->givenReachableTarget();
        $this->givenConstants();
        $roomService = new DirectRoomService(new DirectRoom('abcd', 'Bob Souza'));
        $this->givenRoomService($roomService);
        $this->givenParticipant(128);

        $conversation = $this->resolver->conversation('alice', 'bob');

        $this->assertSame('abcd', $conversation->token());
        $this->assertSame('Bob Souza', $conversation->displayName('alice'));
        $this->assertSame(1, $roomService->calls);
        $this->assertSame(['alice', 'bob'], $roomService->arguments());
    }

    public function testTheConversationIsTheOneTheProductReusesOnASecondCall(): void {
        $this->givenReachableTarget();
        $this->givenConstants();
        $roomService = new DirectRoomService(new DirectRoom('abcd', 'Bob Souza'));
        $this->givenRoomService($roomService);
        $this->givenParticipant(128);

        $first = $this->resolver->conversation('alice', 'bob');
        $second = $this->resolver->conversation('alice', 'bob');

        // The product call is idempotent, which is what makes a retry after a failed send safe.
        $this->assertSame($first->token(), $second->token());
        $this->assertSame(2, $roomService->calls);
    }

    public function testARefusalFromTheProductBecomesTheSameUserNotReachableAnswer(): void {
        $this->givenReachableTarget();
        $this->givenConstants();
        // The product throws InvalidArgumentException for a self chat and RoomNotFoundException for a target the
        // caller may not enumerate. Neither detail belongs to the caller of this tool.
        $this->givenRoomService(new DirectRoomService(null, new RuntimeException('talk is not a friend')));
        $this->givenParticipant(128);

        try {
            $this->resolver->conversation('alice', 'bob');
            $this->fail('a refusal from the product became a success');
        } catch (ConversationAccessException $e) {
            $this->assertSame(Messages::userNotReachable(), $e->getMessage());
            $this->assertSame('talk is not a friend', $e->getPrevious()?->getMessage());
        }
    }

    public function testARoomThatIsReadOnlyIsRefusedEvenThoughItExists(): void {
        $this->givenReachableTarget();
        $this->givenConstants();
        $this->givenRoomService(new DirectRoomService(new DirectRoom('abcd', 'Bob Souza', readOnly: 1)));
        $this->givenParticipant(128);

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(Messages::conversationNotWritable());
        $this->resolver->conversation('alice', 'bob');
    }

    public function testARoomWhereTheCallerLacksTheChatPermissionIsRefused(): void {
        $this->givenReachableTarget();
        $this->givenConstants();
        $this->givenRoomService(new DirectRoomService(new DirectRoom('abcd', 'Bob Souza')));
        $this->givenParticipant(0);

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(Messages::conversationNotWritable());
        $this->resolver->conversation('alice', 'bob');
    }

    public function testSpreedBeingUnavailableIsNotTurnedIntoAMissingUser(): void {
        $this->givenReachableTarget();
        $this->talkServices->method('roomService')->willThrowException(new TalkUnavailableException());

        $this->expectException(TalkUnavailableException::class);
        $this->resolver->conversation('alice', 'bob');
    }

    public function testTheAccessRuleIsAskedAgainBeforeTheRoomIsCreated(): void {
        $this->givenReachableTarget();
        $this->givenConstants();
        $this->givenRoomService(new DirectRoomService(new DirectRoom('abcd', 'Bob Souza')));
        $this->givenParticipant(128);
        $asked = 0;
        $this->shareManager = $this->createMock(IShareManager::class);
        $this->shareManager->method('currentUserCanEnumerateTargetUser')->willReturnCallback(
            static function () use (&$asked): bool {
                $asked++;
                return true;
            },
        );
        $this->resolver = new UserConversationResolver($this->talkServices, $this->userManager, $this->shareManager);

        $this->resolver->conversation('alice', 'bob');

        // Reachability is not carried over from the draft: an approval binds the payload, not what was true before.
        $this->assertSame(1, $asked);
    }
}

final class DirectRoom {
    public function __construct(
        private string $token,
        private string $displayName,
        private int $readOnly = 0,
        private int $type = 1,
    ) {}

    public function getToken(): string {
        return $this->token;
    }

    public function getDisplayName(string $userId): string {
        return $this->displayName;
    }

    public function isFederatedConversation(): bool {
        return false;
    }

    public function getReadOnly(): int {
        return $this->readOnly;
    }

    public function getType(): int {
        return $this->type;
    }
}

/** OCA\Talk\Service\RoomService, reduced to the single call this resolver makes. */
final class DirectRoomService {
    public int $calls = 0;
    /** @var list<string> */
    public array $seen = [];

    public function __construct(
        private ?DirectRoom $room,
        private ?Throwable $failure = null,
    ) {}

    public function createOneToOneConversation(IUser $actor, IUser $target): object {
        $this->calls++;
        $this->seen = [$actor->getUID(), $target->getUID()];
        if ($this->failure !== null) {
            throw $this->failure;
        }
        return $this->room;
    }

    /** @return list<string> */
    public function arguments(): array {
        return $this->seen;
    }
}

/** OCA\Talk\Service\ParticipantService, reduced to the lookup of the caller's own participant. */
final class DirectParticipantService {
    public function __construct(private int $permissions) {}

    public function getParticipant(object $room, string $userId, bool $lookForSession = true): object {
        return new DirectParticipant($this->permissions);
    }
}

final class DirectParticipant {
    public function __construct(private int $permissions) {}

    public function getPermissions(): int {
        return $this->permissions;
    }
}
