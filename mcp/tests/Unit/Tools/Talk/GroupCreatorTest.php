<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Talk;

use InvalidArgumentException;
use OCA\Mcp\Tools\Talk\ConversationAccessException;
use OCA\Mcp\Tools\Talk\DirectContact;
use OCA\Mcp\Tools\Talk\GroupCreator;
use OCA\Mcp\Tools\Talk\Messages;
use OCA\Mcp\Tools\Talk\TalkServices;
use OCA\Mcp\Tools\Talk\TalkUnavailableException;
use OCA\Mcp\Tools\Talk\UserConversationResolver;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class GroupCreatorTest extends TestCase {
    private TalkServices&MockObject $talkServices;
    private IUserManager&MockObject $userManager;
    private UserConversationResolver&MockObject $userConversations;
    private LoggerInterface&MockObject $logger;
    private GroupCreator $groups;

    protected function setUp(): void {
        parent::setUp();
        $this->talkServices = $this->createMock(TalkServices::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->userConversations = $this->createMock(UserConversationResolver::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->groups = new GroupCreator(
            $this->talkServices,
            $this->userManager,
            $this->userConversations,
            $this->logger,
        );

        $this->talkServices->method('conversationConstants')->willReturn([
            'groupType' => 2,
            'actorUsers' => 'users',
        ]);
        $this->userManager->method('get')->willReturnCallback(
            fn (string $uid): ?IUser => $this->createStubbedUser($uid),
        );
    }

    private function createStubbedUser(string $uid): IUser {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);

        return $user;
    }

    private function givenRoomService(GroupRoomService $roomService): void {
        $this->talkServices->method('roomService')->willReturn($roomService);
    }

    private function givenParticipantService(GroupParticipantService $participantService): void {
        $this->talkServices->method('participantService')->willReturn($participantService);
    }

    private function givenContacts(array $contacts): void {
        $this->userConversations->method('contacts')->willReturn($contacts);
    }

    public function testTheGroupIsCreatedWithTheCallerAsOwnerAndTheGuestsInvited(): void {
        $this->givenContacts([new DirectContact('bob', 'Bob Souza'), new DirectContact('carol', 'Carol Lima')]);
        $roomService = new GroupRoomService(new GroupRoom('wxyz', 'Projeto X'));
        $this->givenRoomService($roomService);
        $participantService = new GroupParticipantService();
        $this->givenParticipantService($participantService);

        $result = $this->groups->create('alice', '  Projeto X  ', ['bob', 'carol']);

        $this->assertSame(['alice', 2, 'Projeto X'], $roomService->arguments());
        // One invitation per guest, so a refusal can be told apart from the guests that did get in.
        $this->assertSame(
            [
                [['actorType' => 'users', 'actorId' => 'bob', 'displayName' => 'Bob Souza']],
                [['actorType' => 'users', 'actorId' => 'carol', 'displayName' => 'Carol Lima']],
            ],
            $participantService->participants,
        );
        $this->assertSame('alice', $participantService->addedBy?->getUID());
        $this->assertSame(
            [
                'conversation_token' => 'wxyz',
                'name' => 'Projeto X',
                'participants' => [['id' => 'bob', 'displayName' => 'Bob Souza'], ['id' => 'carol', 'displayName' => 'Carol Lima']],
                'invitations_failed' => [],
            ],
            $result,
        );
    }

    public function testAGroupWithNoGuestsSkipsTheInvitationCall(): void {
        $this->givenContacts([]);
        $this->givenRoomService(new GroupRoomService(new GroupRoom('wxyz', 'Projeto X')));
        // Calling the product to add nobody would be a wasted round trip and a chance to fail for nothing.
        $participantService = new GroupParticipantService();
        $this->givenParticipantService($participantService);

        $result = $this->groups->create('alice', 'Projeto X', []);

        $this->assertSame([], $result['participants']);
        $this->assertSame(0, $participantService->calls);
    }

    public function testNobodyIsLookedUpBeforeTheGuestListIsAccepted(): void {
        $this->givenContacts([]);
        $roomService = new GroupRoomService(new GroupRoom('wxyz', 'Projeto X'));
        $this->givenRoomService($roomService);

        try {
            $this->groups->create('alice', 'Projeto X', array_fill(0, GroupCreator::MAX_PARTICIPANTS + 1, 'bob'));
            $this->fail('a group with more guests than the limit was accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(Messages::tooManyParticipants(GroupCreator::MAX_PARTICIPANTS), $e->getMessage());
        }

        // Nothing exists yet, so a refused list must not have reached the product.
        $this->assertSame(0, $roomService->calls);
    }

    public function testAnAccountOutOfReachStopsTheCallBeforeTheRoomExists(): void {
        $this->userConversations->method('contacts')
            ->willThrowException(new ConversationAccessException(Messages::userNotReachable()));
        $roomService = new GroupRoomService(new GroupRoom('wxyz', 'Projeto X'));
        $this->givenRoomService($roomService);

        try {
            $this->groups->create('alice', 'Projeto X', ['ghost']);
            $this->fail('an unreachable guest did not stop the creation');
        } catch (ConversationAccessException $e) {
            $this->assertSame(Messages::userNotReachable(), $e->getMessage());
        }

        // A guest nobody may reach is refused before the room, never after it.
        $this->assertSame(0, $roomService->calls);
    }

    public function testWhatTheProductRefusesIsAnsweredAsACreationThatDidNotHappen(): void {
        $this->givenContacts([]);
        $this->givenRoomService(new GroupRoomService(null, new RuntimeException('nope')));

        try {
            $this->groups->create('alice', 'Projeto X', []);
            $this->fail('a refused creation was answered as a success');
        } catch (ConversationAccessException $e) {
            $this->assertSame(Messages::conversationNotCreated(), $e->getMessage());
        }
    }

    public function testAnUnavailableTalkIsNotDressedUpAsARefusal(): void {
        $this->givenContacts([]);
        $this->givenRoomService(new GroupRoomService(null, new TalkUnavailableException()));

        $this->expectException(TalkUnavailableException::class);

        $this->groups->create('alice', 'Projeto X', []);
    }

    public function testAGuestTheProductRefusesIsReportedByNameAndTheOthersStillGetIn(): void {
        $this->givenContacts([
            new DirectContact('bob', 'Bob Souza'),
            new DirectContact('carol', 'Carol Lima'),
            new DirectContact('dave', 'Dave Rocha'),
        ]);
        $this->givenRoomService(new GroupRoomService(new GroupRoom('wxyz', 'Projeto X')));
        $participantService = new GroupParticipantService(['carol' => new RuntimeException('banned from room 42')]);
        $this->givenParticipantService($participantService);
        $this->logger->expects($this->once())->method('warning');

        $result = $this->groups->create('alice', 'Projeto X', ['bob', 'carol', 'dave']);

        // The room is real and the user will find it in their own list: pretending it failed would be a lie, and
        // deleting it is not something this tool may decide on its own. So the answer says who got in and who did
        // not, and the product's own wording, which may name internals, stays in the log.
        $this->assertSame('wxyz', $result['conversation_token']);
        $this->assertSame(
            [['id' => 'bob', 'displayName' => 'Bob Souza'], ['id' => 'dave', 'displayName' => 'Dave Rocha']],
            $result['participants'],
        );
        $this->assertSame(
            [['id' => 'carol', 'displayName' => 'Carol Lima', 'error' => Messages::invitationFailed()]],
            $result['invitations_failed'],
        );
        $this->assertSame(3, $participantService->calls);
    }

    public function testEveryGuestRefusedStillReturnsTheGroupThatExists(): void {
        $this->givenContacts([new DirectContact('bob', 'Bob Souza')]);
        $this->givenRoomService(new GroupRoomService(new GroupRoom('wxyz', 'Projeto X')));
        $this->givenParticipantService(new GroupParticipantService(['bob' => new RuntimeException('no')]));

        $result = $this->groups->create('alice', 'Projeto X', ['bob']);

        $this->assertSame('wxyz', $result['conversation_token']);
        $this->assertSame([], $result['participants']);
        $this->assertSame(['bob'], array_column($result['invitations_failed'], 'id'));
    }

    public function testAnUnavailableParticipantServiceStopsTheCallBeforeTheRoomExists(): void {
        $this->givenContacts([new DirectContact('bob', 'Bob Souza')]);
        $roomService = new GroupRoomService(new GroupRoom('wxyz', 'Projeto X'));
        $this->givenRoomService($roomService);
        $this->talkServices->method('participantService')->willThrowException(new TalkUnavailableException());

        try {
            $this->groups->create('alice', 'Projeto X', ['bob']);
            $this->fail('the room was created without a way to invite anyone');
        } catch (TalkUnavailableException) {
        }

        // Discovering it after the creation would hide a conversation that is already in the user's list.
        $this->assertSame(0, $roomService->calls);
    }

    public function testGuestsAreResolvedWithTheirIdsNamedInARefusal(): void {
        $this->userConversations->expects($this->once())
            ->method('contacts')
            ->with('alice', ['bob'], true)
            ->willReturn([]);
        $this->givenRoomService(new GroupRoomService(new GroupRoom('wxyz', 'Projeto X')));
        $this->givenParticipantService(new GroupParticipantService());

        $this->groups->create('alice', 'Projeto X', ['bob']);
    }

    public function testAnAuthenticatedAccountThatNoLongerExistsIsARefusal(): void {
        $gone = $this->createMock(IUserManager::class);
        $gone->method('get')->willReturn(null);
        $this->groups = new GroupCreator($this->talkServices, $gone, $this->userConversations, $this->logger);
        $this->givenContacts([]);
        $roomService = new GroupRoomService(new GroupRoom('wxyz', 'Projeto X'));
        $this->givenRoomService($roomService);

        try {
            $this->groups->create('alice', 'Projeto X', []);
            $this->fail('a creation without an owner was attempted');
        } catch (ConversationAccessException $e) {
            $this->assertSame(Messages::conversationNotCreated(), $e->getMessage());
        }

        $this->assertSame(0, $roomService->calls);
    }

    public function testTheNameIsTrimmedAndABlankOneIsAClientMistake(): void {
        $this->assertSame('Projeto X', GroupCreator::normalizeName('  Projeto X  '));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Messages::invalidGroupName());

        GroupCreator::normalizeName('   ');
    }

    public function testANameLongerThanTheLimitIsAClientMistake(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Messages::groupNameTooLong());

        GroupCreator::normalizeName(str_repeat('a', GroupCreator::MAX_NAME_LENGTH + 1));
    }
}

/** OCA\Talk\Service\RoomService, reduced to the call that opens a group. */
final class GroupRoomService {
    public int $calls = 0;
    /** @var list<mixed> */
    private array $seen = [];

    public function __construct(
        private ?GroupRoom $room,
        private ?\Throwable $failure = null,
    ) {}

    public function createConversation(int $type, string $name, ?IUser $owner): object {
        $this->calls++;
        $this->seen = [$owner?->getUID(), $type, $name];
        if ($this->failure !== null) {
            throw $this->failure;
        }
        return $this->room;
    }

    /** @return list<mixed> */
    public function arguments(): array {
        return $this->seen;
    }
}

/** OCA\Talk\Service\ParticipantService, reduced to the invitation call. */
final class GroupParticipantService {
    /** @var list<list<array<string, string>>> participants of each call, in order */
    public array $participants = [];
    public ?IUser $addedBy = null;
    public int $calls = 0;

    /** @param array<string, \Throwable> $failures failure to throw for the call that invites that account */
    public function __construct(private array $failures = []) {}

    public function addUsers(object $room, array $participants, ?IUser $addedBy = null): array {
        $this->calls++;
        $this->participants[] = $participants;
        $this->addedBy = $addedBy;
        foreach ($participants as $participant) {
            if (isset($this->failures[$participant['actorId']])) {
                throw $this->failures[$participant['actorId']];
            }
        }

        return $participants;
    }
}

/** OCA\Talk\Room, reduced to the token the creator reads back. */
final class GroupRoom {
    public function __construct(private string $token, private string $name) {}

    public function getToken(): string {
        return $this->token;
    }
}
