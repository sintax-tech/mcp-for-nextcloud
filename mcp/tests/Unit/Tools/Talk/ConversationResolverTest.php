<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Talk;

use InvalidArgumentException;
use OCA\Mcp\Tools\Talk\Conversation;
use OCA\Mcp\Tools\Talk\ConversationAccessException;
use OCA\Mcp\Tools\Talk\ConversationResolver;
use OCA\Mcp\Tools\Talk\Messages;
use OCA\Mcp\Tools\Talk\TalkServices;
use OCA\Mcp\Tools\Talk\TalkUnavailableException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ConversationResolverTest extends TestCase {
    private TalkServices&MockObject $talkServices;
    private ConversationResolver $resolver;

    protected function setUp(): void {
        parent::setUp();
        $this->talkServices = $this->createMock(TalkServices::class);
        $this->resolver = new ConversationResolver($this->talkServices);
    }

    public function testMalformedTokenIsAnArgumentErrorBeforeAnyTalkCall(): void {
        $this->talkServices->expects($this->never())->method('manager');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Messages::invalidToken());
        $this->resolver->resolveForReading('alice', 'ABC');
    }

    public function testTokenWithUnsupportedCharactersIsAnArgumentError(): void {
        $this->talkServices->expects($this->never())->method('manager');

        $this->expectException(InvalidArgumentException::class);
        $this->resolver->resolveForReading('alice', 'token_com_underscore');
    }

    public function testReadingResolvesRoomAndParticipant(): void {
        [$room, $participant] = $this->givenRoomAndParticipant();

        $conversation = $this->resolver->resolveForReading('alice', 'abcd');

        $this->assertInstanceOf(Conversation::class, $conversation);
        $this->assertSame($room, $conversation->room);
        $this->assertSame($participant, $conversation->participant);
    }

    public function testReadingNeverMarksAnythingAsRead(): void {
        $room = $this->createMock(ResolverRoomGateway::class);
        $room->expects($this->never())->method('setReadOnly');
        $room->expects($this->never())->method('setLobbyState');
        $participant = $this->createMock(ResolverParticipantGateway::class);
        $participant->expects($this->never())->method('markAsRead');
        $this->givenServicesReturning($room, $participant);

        $this->resolver->resolveForReading('alice', 'abcd');
    }

    public function testMissingConversationAndMissingMembershipAnswerTheSameWay(): void {
        $notThere = $this->givenFailingBackend()->captureForTest('abcd');

        [$room, $participant] = $this->givenRoomAndParticipant();
        $services = $this->createMock(TalkServices::class);
        $services->method('manager')->willReturn($this->givenManager($room));
        $services->method('participantService')->willReturn($this->givenParticipantServiceThatThrows());
        $notAMember = (new FailingConversationResolver($services))->captureForTest('abcd');

        // The caller must not be able to tell "no such conversation" from "you are not in it".
        $this->assertSame(Messages::conversationNotFound(), $notThere);
        $this->assertSame($notThere, $notAMember);
    }

    public function testWritingRefusesAReadOnlyConversation(): void {
        // An ordinary room (type 1) with every other condition satisfied, so the read-only flag is the only reason to refuse.
        $this->givenRoom(readOnly: 1, type: 1, federated: false, lobby: 0, permissions: 128);

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(Messages::conversationNotWritable());
        $this->resolver->resolveForWriting('alice', 'abcd');
    }

    public function testWritingRefusesAChangelog(): void {
        $this->givenRoom(readOnly: 0, type: 7, federated: false, lobby: 0, permissions: 128);

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(Messages::conversationNotWritable());
        $this->resolver->resolveForWriting('alice', 'abcd');
    }

    public function testWritingRefusesAFederatedConversation(): void {
        $this->givenRoom(readOnly: 0, type: 1, federated: true, lobby: 0, permissions: 128);

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(Messages::conversationNotWritable());
        $this->resolver->resolveForWriting('alice', 'abcd');
    }

    public function testWritingRefusesAParticipantWithoutChatPermission(): void {
        $this->givenRoom(readOnly: 0, type: 1, federated: false, lobby: 0, permissions: 0);

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(Messages::conversationNotWritable());
        $this->resolver->resolveForWriting('alice', 'abcd');
    }

    /**
     * The chat permission is one bit among others, and Talk hands out custom permission sets: a participant
     * holding any combination without that bit cannot write, whatever else they hold.
     *
     * @return array<string, array{int}>
     */
    public static function permissionsWithoutTheChatBitProvider(): array {
        return ['custom flag only' => [1], 'lobby bypass only' => [8], 'every other bit' => [127], 'two other bits' => [1 | 8 | 64]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('permissionsWithoutTheChatBitProvider')]
    public function testWritingRefusesAnyPermissionSetWithoutTheChatBit(int $permissions): void {
        $this->givenRoom(readOnly: 0, type: 1, federated: false, lobby: 0, permissions: $permissions);

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(Messages::conversationNotWritable());
        $this->resolver->resolveForWriting('alice', 'abcd');
    }

    /** @return array<string, array{int}> */
    public static function permissionsWithTheChatBitProvider(): array {
        return ['chat bit alone' => [128], 'chat and another' => [128 | 1], 'all bits' => [255]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('permissionsWithTheChatBitProvider')]
    public function testWritingAllowsAnyPermissionSetWithTheChatBit(int $permissions): void {
        $this->givenRoom(readOnly: 0, type: 1, federated: false, lobby: 0, permissions: $permissions);

        $this->assertSame('abcd', $this->resolver->resolveForWriting('alice', 'abcd')->room->getToken());
    }

    public function testAnActiveLobbyNeedsTheBypassBitSpecificallyNotJustAnyOtherBit(): void {
        $this->givenRoom(readOnly: 0, type: 1, federated: false, lobby: 2, permissions: 255 & ~8);

        $this->expectException(ConversationAccessException::class);
        $this->resolver->resolveForWriting('alice', 'abcd');
    }

    public function testWritingRefusesAnActiveLobbyWithoutTheBypassPermission(): void {
        $this->givenRoom(readOnly: 0, type: 1, federated: false, lobby: 2, permissions: 128);

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(Messages::conversationNotWritable());
        $this->resolver->resolveForWriting('alice', 'abcd');
    }

    public function testWritingAllowsAnActiveLobbyWithTheBypassPermission(): void {
        $this->givenRoom(readOnly: 0, type: 1, federated: false, lobby: 2, permissions: 128 | 8);

        $conversation = $this->resolver->resolveForWriting('alice', 'abcd');

        $this->assertSame('abcd', $conversation->room->getToken());
    }

    public function testWritingAllowsAnOpenRoomWithChatPermission(): void {
        $this->givenRoom(readOnly: 0, type: 1, federated: false, lobby: 0, permissions: 128);

        $this->assertInstanceOf(Conversation::class, $this->resolver->resolveForWriting('alice', 'abcd'));
    }

    /**
     * Talk 24 (Nextcloud 34) no longer lets Room::getLobbyState() open a lobby whose timer has passed: its own
     * controllers call RoomService::validateLobbyTimer() first. Without that call a webinar past its start time would
     * stay closed to the module.
     */
    public function testWritingOpensALobbyWhoseTimerHasPassedOnTalk24(): void {
        $room = $this->givenLobbyRoom(lobby: 2);
        $roomService = new class {
            /** @var list<object> */
            public array $validated = [];

            public function validateLobbyTimer(object $room): void {
                $this->validated[] = $room;
                $room->setLobbyState(0);
            }
        };
        $this->talkServices->method('roomService')->willReturn($roomService);

        $conversation = $this->resolver->resolveForWriting('alice', 'abcd');

        $this->assertSame($room, $conversation->room);
        $this->assertSame([$room], $roomService->validated);
    }

    public function testWritingKeepsRefusingALobbyWhoseTimerHasNotPassedOnTalk24(): void {
        $this->givenLobbyRoom(lobby: 2);
        $this->talkServices->method('roomService')->willReturn(new class {
            public function validateLobbyTimer(object $room): void {
            }
        });

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(Messages::conversationNotWritable());
        $this->resolver->resolveForWriting('alice', 'abcd');
    }

    /** Before Talk 24 getLobbyState() expires the timer itself, and RoomService has no validateLobbyTimer(). */
    public function testWritingReliesOnGetLobbyStateWhereRoomServiceCannotValidateTheTimer(): void {
        $this->givenLobbyRoom(lobby: 2);
        $this->talkServices->method('roomService')->willReturn(new class {
        });

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(Messages::conversationNotWritable());
        $this->resolver->resolveForWriting('alice', 'abcd');
    }

    /** A failing timer check leaves the lobby as Talk reports it: closed, never opened by accident. */
    public function testAFailingLobbyTimerCheckKeepsTheLobbyClosed(): void {
        $this->givenLobbyRoom(lobby: 2);
        $this->talkServices->method('roomService')->willReturn(new class {
            public function validateLobbyTimer(object $room): void {
                throw new RuntimeException('database gone');
            }
        });

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(Messages::conversationNotWritable());
        $this->resolver->resolveForWriting('alice', 'abcd');
    }

    /** No lobby, nothing to expire: the room service is not even resolved. */
    public function testWritingWithoutLobbyNeverTouchesTheLobbyTimer(): void {
        $this->givenLobbyRoom(lobby: 0);
        $this->talkServices->expects($this->never())->method('roomService');

        $this->assertSame('abcd', $this->resolver->resolveForWriting('alice', 'abcd')->room->getToken());
    }

    public function testUnavailableTalkStaysUnavailableThroughTheResolver(): void {
        $this->talkServices->method('manager')->willThrowException(new TalkUnavailableException());

        $this->expectException(TalkUnavailableException::class);
        $this->resolver->resolveForReading('alice', 'abcd');
    }

    /**
     * @return array{ResolverRoomGateway&MockObject, ResolverParticipantGateway&MockObject}
     */
    private function givenRoomAndParticipant(): array {
        $room = $this->createMock(ResolverRoomGateway::class);
        $room->method('getToken')->willReturn('abcd');
        $participant = $this->createMock(ResolverParticipantGateway::class);
        $participant->method('getPermissions')->willReturn(128);
        $this->givenServicesReturning($room, $participant);

        return [$room, $participant];
    }

    private function givenServicesReturning(object $room, object $participant): void {
        $this->talkServices->method('manager')->willReturn($this->givenManager($room));
        $this->talkServices->method('participantService')->willReturn($this->givenParticipantService($participant));
    }

    /**
     * @param int $readOnly Value the room reports as read-only
     * @param int $type Room type, where 7 is changelog
     * @param bool $federated Whether the conversation is federated
     * @param int $lobby Lobby state, where 0 means none
     * @param int $permissions Participant permission bits
     */
    private function givenRoom(int $readOnly, int $type, bool $federated, int $lobby, int $permissions): void {
        $room = $this->createMock(ResolverRoomGateway::class);
        $room->method('getToken')->willReturn('abcd');
        $room->method('getReadOnly')->willReturn($readOnly);
        $room->method('getType')->willReturn($type);
        $room->method('isFederatedConversation')->willReturn($federated);
        $room->method('getLobbyState')->willReturn($lobby);
        $participant = $this->createMock(ResolverParticipantGateway::class);
        $participant->method('getPermissions')->willReturn($permissions);
        $this->givenServicesReturning($room, $participant);

        $this->talkServices->method('conversationConstants')->willReturn([
            'chatPermission' => 128,
            'lobbyIgnorePermission' => 8,
            'readOnly' => 1,
            'changelogType' => 7,
            'lobbyNone' => 0,
        ]);
    }

    /**
     * An ordinary writable room whose lobby state can change, as Talk 24's RoomService::validateLobbyTimer() changes
     * it, with a participant holding the chat permission but not the lobby bypass.
     *
     * @param int $lobby Initial lobby state, where 0 means none
     */
    private function givenLobbyRoom(int $lobby): ResolverRoomGateway {
        $room = new class ($lobby) implements ResolverRoomGateway {
            public function __construct(private int $lobby) {}

            public function getToken(): string {
                return 'abcd';
            }

            public function getType(): int {
                return 2;
            }

            public function getReadOnly(): int {
                return 0;
            }

            public function getLobbyState(): int {
                return $this->lobby;
            }

            public function isFederatedConversation(): bool {
                return false;
            }

            public function setReadOnly(int $readOnly): void {
            }

            public function setLobbyState(int $state): void {
                $this->lobby = $state;
            }
        };
        $participant = $this->createMock(ResolverParticipantGateway::class);
        $participant->method('getPermissions')->willReturn(128);
        $this->givenServicesReturning($room, $participant);
        $this->talkServices->method('conversationConstants')->willReturn([
            'chatPermission' => 128,
            'lobbyIgnorePermission' => 8,
            'readOnly' => 1,
            'changelogType' => 7,
            'lobbyNone' => 0,
        ]);

        return $room;
    }

    private function givenManager(object $room): object {
        return new class ($room) {
            public function __construct(private object $room) {}

            public function getRoomForUserByToken(string $token, ?string $userId, ?string $sessionId = null): object {
                return $this->room;
            }
        };
    }

    private function givenParticipantService(object $participant): object {
        return new class ($participant) {
            public function __construct(private object $participant) {}

            public function getParticipant(object $room, string $userId, bool $lazy = false): object {
                return $this->participant;
            }
        };
    }

    private function givenParticipantServiceThatThrows(): object {
        return new class {
            public function getParticipant(object $room, string $userId, bool $lazy = false): object {
                throw new RuntimeException('Participant not found');
            }
        };
    }

    private function givenFailingBackend(): FailingConversationResolver {
        $services = $this->createMock(TalkServices::class);
        $services->method('manager')->willReturn(new class {
            public function getRoomForUserByToken(string $token, ?string $userId, ?string $sessionId = null): object {
                throw new RuntimeException('Room not found');
            }
        });
        $services->method('participantService')->willReturn($this->givenParticipantServiceThatThrows());

        return new FailingConversationResolver($services);
    }
}

/**
 * The room surface the resolver touches, plus the mutating calls that reading must never make.
 */
interface ResolverRoomGateway {
    public function getToken(): string;

    public function getType(): int;

    public function getReadOnly(): int;

    public function getLobbyState(): int;

    public function isFederatedConversation(): bool;

    public function setReadOnly(int $readOnly): void;

    public function setLobbyState(int $state): void;
}

/** The participant surface the resolver touches, plus the read-marking call that reading must never make. */
interface ResolverParticipantGateway {
    public function getPermissions(): int;

    public function markAsRead(): void;
}

/** Exposes the failure message so the test can compare what two different refusals say. */
class FailingConversationResolver extends ConversationResolver {
    /**
     * @param string $token Conversation token
     * @return string The message the caller would receive
     */
    public function captureForTest(string $token): string {
        try {
            $this->resolveForReading('alice', $token);
            throw new RuntimeException('Expected a conversation access failure.');
        } catch (ConversationAccessException $e) {
            return $e->getMessage();
        }
    }
}
