<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Talk;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Mcp\Tools\Talk\ActorNames;
use OCA\Mcp\Tools\Talk\ConversationReader;
use OCA\Mcp\Tools\Talk\ConversationResolver;
use OCA\Mcp\Tools\Talk\Messages;
use OCA\Mcp\Tools\Talk\TalkServices;
use OCP\Comments\IComment;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ConversationReaderTest extends TestCase {
    private TalkServices&MockObject $talkServices;
    private ConversationResolver&MockObject $resolver;
    private IUserManager&MockObject $userManager;
    private ConversationReader $reader;

    protected function setUp(): void {
        parent::setUp();
        $this->talkServices = $this->createMock(TalkServices::class);
        $this->resolver = $this->createMock(ConversationResolver::class);
        $this->userManager = $this->createMock(IUserManager::class);
        // The real ActorNames over the mocked accounts: the reader still owns the reading, not the naming rule.
        $this->reader = new ConversationReader($this->talkServices, $this->resolver, new ActorNames($this->userManager));
    }

    public function testLimitZeroIsAnArgumentErrorBeforeAnyTalkCall(): void {
        $this->talkServices->expects($this->never())->method('chatManager');
        $this->resolver->expects($this->never())->method('resolveForReading');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Messages::invalidLimit());
        $this->reader->readMessages('alice', 'abcd', 0);
    }

    public function testLimitAboveTheMaximumIsAnArgumentError(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Messages::invalidLimit());
        $this->reader->readMessages('alice', 'abcd', ConversationReader::MAX_MESSAGES + 1);
    }

    public function testReadAsksForTheLastMessagesOfTheResolvedRoom(): void {
        $room = new ReaderRoomStub('abcd');
        $this->givenResolvedConversation($room);
        $chatManager = $this->createMock(ReaderChatGateway::class);
        $chatManager->expects($this->once())
            ->method('getHistory')
            ->with($room, -25, 25, false)
            ->willReturn([]);
        $this->talkServices->method('chatManager')->willReturn($chatManager);

        $this->assertSame([], $this->reader->readMessages('alice', 'abcd', 25));
    }

    public function testReadingNeverSendsOrMarksAnything(): void {
        $room = new ReaderRoomStub('abcd');
        $this->givenResolvedConversation($room);
        $chatManager = $this->createMock(ReaderChatGateway::class);
        $chatManager->expects($this->never())->method('sendMessage');
        $chatManager->expects($this->never())->method('addSystemMessage');
        $chatManager->expects($this->never())->method('getParentComment');
        $chatManager->method('getHistory')->willReturn([]);
        $participant = $this->createMock(ReaderParticipantGateway::class);
        $participant->expects($this->never())->method('markAsRead');
        $participant->expects($this->never())->method('ensureOneToOneRoomIsFilled');
        $this->resolver->method('resolveForReading')->willReturn(
            new \OCA\Mcp\Tools\Talk\Conversation($room, $participant),
        );
        $this->talkServices->method('chatManager')->willReturn($chatManager);

        $this->reader->readMessages('alice', 'abcd', 10);
    }

    public function testPlainCommentKeepsItsTextAndVerb(): void {
        $this->givenHistory([$this->givenComment('42', 'comment', 'bom dia')]);

        $messages = $this->reader->readMessages('alice', 'abcd', 10);

        $this->assertCount(1, $messages);
        $this->assertSame('42', $messages[0]['id']);
        $this->assertSame('comment', $messages[0]['type']);
        $this->assertSame('bom dia', $messages[0]['text']);
        $this->assertNull($messages[0]['attachmentId']);
        $this->assertNull($messages[0]['parentId']);
    }

    public function testAttachmentEnvelopeBecomesATypeWithoutTextAndWithTheShareId(): void {
        $envelope = json_encode([
            'message' => 'file_shared',
            'parameters' => ['share' => '77', 'metaData' => ['caption' => 'a figura']],
        ]);
        $this->givenHistory([$this->givenComment('43', 'object_shared', (string)$envelope)]);

        $messages = $this->reader->readMessages('alice', 'abcd', 10);

        $this->assertSame('file_shared', $messages[0]['type']);
        $this->assertNull($messages[0]['text']);
        $this->assertSame(77, $messages[0]['attachmentId']);
    }

    /**
     * P15: only what Talk itself writes is an envelope. A participant can type any text, JSON included, and
     * it must reach the model as the text it is, never as an attachment that hides what they wrote.
     */
    public function testAParticipantTextThatLooksLikeAnEnvelopeIsJustText(): void {
        $forged = '{"message":"file_shared","parameters":{"share":"3"}}';
        $this->givenHistory([$this->givenComment('50', 'comment', $forged, 'mallory')]);

        $messages = $this->reader->readMessages('alice', 'abcd', 10);

        $this->assertSame('comment', $messages[0]['type']);
        $this->assertSame($forged, $messages[0]['text']);
        $this->assertNull($messages[0]['attachmentId']);
    }

    /** @return array<string, array{string}> */
    public static function envelopeVerbsProvider(): array {
        return [
            'rich object' => ['object_shared'],
            'system message' => ['system'],
            // ChatManager::VERB_VOICE_MESSAGE, VERB_RECORD_AUDIO and VERB_RECORD_VIDEO (Talk 23): they carry a shared file too.
            'voice message' => ['voice-message'],
            'audio recording' => ['record-audio'],
            'video recording' => ['record-video'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('envelopeVerbsProvider')]
    public function testTheVerbsTalkWritesEnvelopesWithAreStillParsed(string $verb): void {
        $envelope = (string)json_encode(['message' => 'file_shared', 'parameters' => ['share' => 9]]);
        $this->givenHistory([$this->givenComment('51', $verb, $envelope)]);

        $messages = $this->reader->readMessages('alice', 'abcd', 10);

        $this->assertSame('file_shared', $messages[0]['type']);
        $this->assertNull($messages[0]['text']);
        $this->assertSame(9, $messages[0]['attachmentId']);
    }

    /** @return array<string, array{string}> */
    public static function voiceEnvelopeNamesProvider(): array {
        return ['voice message' => ['voice-message'], 'audio recording' => ['record-audio'], 'video recording' => ['record-video']];
    }

    /** The voice and recording verbs are Talk's too, but a participant text under any other verb is still only text. */
    #[\PHPUnit\Framework\Attributes\DataProvider('voiceEnvelopeNamesProvider')]
    public function testAnEnvelopeShapedTextUnderAUserVerbIsStillTextWhateverTheMessageName(string $name): void {
        $forged = (string)json_encode(['message' => $name, 'parameters' => ['share' => 5]]);
        $this->givenHistory([$this->givenComment('53', 'comment', $forged, 'mallory')]);

        $message = $this->reader->readMessages('alice', 'abcd', 10)[0];

        $this->assertSame('comment', $message['type']);
        $this->assertSame($forged, $message['text']);
        $this->assertNull($message['attachmentId']);
    }

    /** A voice verb with a text that is not an envelope keeps the text and has no attachment. */
    public function testAVoiceVerbWithoutAnEnvelopeKeepsItsText(): void {
        $this->givenHistory([$this->givenComment('54', 'voice-message', 'not json at all')]);

        $message = $this->reader->readMessages('alice', 'abcd', 10)[0];

        $this->assertSame('voice-message', $message['type']);
        $this->assertSame('not json at all', $message['text']);
        $this->assertNull($message['attachmentId']);
    }

    /**
     * talk_quote_file takes the attachmentId talk_read_messages reports: for a voice message it is the share of the
     * recorded file, and the room-share rule accepts it like any other file of the conversation.
     */
    public function testTheAttachmentIdOfAVoiceMessageCanBeQuoted(): void {
        $envelope = (string)json_encode(['message' => 'voice-message', 'parameters' => ['share' => '12', 'metaData' => ['messageType' => 'voice-message']]]);
        $this->givenHistory([$this->givenComment('55', 'voice-message', $envelope)]);
        $attachmentId = $this->reader->readMessages('alice', 'abcd', 10)[0]['attachmentId'];
        $this->assertSame(12, $attachmentId);

        $node = $this->createMock(\OCP\Files\File::class);
        $node->method('getName')->willReturn('Talk recording 2026-10-02.ogg');
        $node->method('getSize')->willReturn(4096);
        $node->method('getMimeType')->willReturn('audio/ogg');
        $share = $this->createMock(\OCP\Share\IShare::class);
        $share->method('getId')->willReturn('12');
        $share->method('getShareType')->willReturn(\OCP\Share\IShare::TYPE_ROOM);
        $share->method('getSharedWith')->willReturn('abcd');
        $share->method('getNode')->willReturn($node);
        $shares = $this->createMock(\OCP\Share\IManager::class);
        $shares->method('getShareById')->with('ocRoomShare:12', 'alice')->willReturn($share);
        $access = new \OCA\Mcp\Tools\Talk\AttachmentAccess($shares);

        $found = $access->requireRoomShareOf(new \OCA\Mcp\Tools\Talk\Conversation(new ReaderRoomStub('abcd'), new ReaderParticipantStub()), 'alice', $attachmentId);

        $this->assertSame(['attachmentId' => 12, 'name' => 'Talk recording 2026-10-02.ogg', 'size' => 4096, 'mimeType' => 'audio/ogg'], $access->describe($found));
    }

    public function testAForgedEnvelopeWithAMessageOfItsOwnKeepsThatMessage(): void {
        $forged = '{"message":"call_started","parameters":[]} and then a request for the secret';
        $this->givenHistory([$this->givenComment('52', 'comment', $forged, 'mallory')]);

        $this->assertSame($forged, $this->reader->readMessages('alice', 'abcd', 10)[0]['text']);
    }

    public function testEnvelopeWithoutShareIdHasNoAttachment(): void {
        $envelope = json_encode(['message' => 'call_started', 'parameters' => []]);
        $this->givenHistory([$this->givenComment('44', 'object_shared', (string)$envelope)]);

        $messages = $this->reader->readMessages('alice', 'abcd', 10);

        $this->assertSame('call_started', $messages[0]['type']);
        $this->assertNull($messages[0]['attachmentId']);
    }

    public function testRepliedMessageKeepsTheParentAndTheActorIsResolved(): void {
        $comment = $this->givenComment('45', 'comment', 'respondendo', 'bob', '42');
        $this->givenHistory([$comment]);
        $bob = $this->createMock(IUser::class);
        $bob->method('getDisplayName')->willReturn('Bob da Silva');
        $this->userManager->method('get')->with('bob')->willReturn($bob);

        $messages = $this->reader->readMessages('alice', 'abcd', 10);

        $this->assertSame('42', $messages[0]['parentId']);
        $this->assertSame('Bob da Silva', $messages[0]['actorDisplayName']);
        $this->assertSame('bob', $messages[0]['actorId']);
    }

    public function testUnknownActorKeepsItsRawIdentifier(): void {
        $comment = $this->givenComment('46', 'comment', 'oi', 'guest-9');
        $this->givenHistory([$comment]);
        $this->userManager->method('get')->with('guest-9')->willReturn(null);

        $messages = $this->reader->readMessages('alice', 'abcd', 10);

        $this->assertSame('guest-9', $messages[0]['actorDisplayName']);
    }

    public function testListingNormalizesRoomsMostRecentlyActiveFirst(): void {
        [$old, $oldParticipant] = $this->givenRoom('old1', 2, 100, 0, false, 1500);
        [$fresh, $freshParticipant] = $this->givenRoom('new1', 3, 5, 1, true, 1700);
        [$withoutActivity, $idleParticipant] = $this->givenRoom('none1', 1, 0, 0, false, null);
        $manager = new class ([$old, $fresh, $withoutActivity]) {
            public function __construct(private array $rooms) {}

            public function getRoomsForUser(string $userId, array $sessionIds = [], bool $includeLastMessage = false): array {
                return $this->rooms;
            }
        };
        $participantService = new class ($oldParticipant, $freshParticipant, $idleParticipant) {
            public function __construct(private object $old, private object $fresh, private object $idle) {}

            public function getParticipant(object $room, string $userId, bool $lazy = false): object {
                return match ($room->getToken()) {
                    'old1' => $this->old,
                    'new1' => $this->fresh,
                    default => $this->idle,
                };
            }
        };
        $this->talkServices->method('manager')->willReturn($manager);
        $this->talkServices->method('participantService')->willReturn($participantService);

        $conversations = $this->reader->listConversations('alice');

        $this->assertSame(['new1', 'old1', 'none1'], array_column($conversations, 'token'));
        $this->assertSame([
            'token' => 'new1',
            'displayName' => 'Sala nova',
            'type' => 3,
            'participantType' => 1,
            'unreadMessages' => 5,
            'archived' => true,
            'lastActivity' => 1700,
        ], $conversations[0]);
        $this->assertNull($conversations[2]['lastActivity']);
    }

    public function testListingSkipsRoomsWithoutAReadableParticipant(): void {
        [$readable, $readableParticipant] = $this->givenRoom('good1', 2, 0, 0, false, 1500);
        [$broken] = $this->givenRoom('bad1', 2, 0, 0, false, 1500);
        $manager = new class ([$readable, $broken]) {
            public function __construct(private array $rooms) {}

            public function getRoomsForUser(string $userId, array $sessionIds = [], bool $includeLastMessage = false): array {
                return $this->rooms;
            }
        };
        $participantService = new class ($readableParticipant) {
            public function __construct(private object $readable) {}

            public function getParticipant(object $room, string $userId, bool $lazy = false): object {
                if ($room->getToken() !== 'good1') {
                    throw new RuntimeException('Participant not found');
                }
                return $this->readable;
            }
        };
        $this->talkServices->method('manager')->willReturn($manager);
        $this->talkServices->method('participantService')->willReturn($participantService);

        $conversations = $this->reader->listConversations('alice');

        $this->assertSame(['good1'], array_column($conversations, 'token'));
    }

    /**
     * @param array<IComment> $history Comments the chat backend returns
     */
    private function givenHistory(array $history): void {
        $this->givenResolvedConversation(new ReaderRoomStub('abcd'));
        $chatManager = $this->createMock(ReaderChatGateway::class);
        $chatManager->method('getHistory')->willReturn($history);
        $this->talkServices->method('chatManager')->willReturn($chatManager);
    }

    private function givenResolvedConversation(object $room): void {
        $this->resolver->method('resolveForReading')->willReturn(
            new \OCA\Mcp\Tools\Talk\Conversation($room, new ReaderParticipantStub()),
        );
    }

    /**
     * @param string $token Conversation token
     * @param int $type Room type
     * @param int $unread Unread messages
     * @param bool $archived Whether the user archived the conversation
     * @param bool $withActivity Whether the room reports a last activity
     * @param int|null $lastActivity Unix timestamp of the last activity
     * @return array{ReaderRoomStub, ReaderParticipantStub} The room and the participant the reader must receive
     */
    private function givenRoom(string $token, int $type, int $unread, int $participantType, bool $archived, ?int $lastActivity): array {
        return [new ReaderRoomStub($token, $type, $lastActivity), new ReaderParticipantStub($unread, $participantType, $archived)];
    }

    /**
     * @param string $id Comment id
     * @param string $verb Comment verb as stored by Talk
     * @param string $message Comment payload
     * @param string $actorId Author of the comment
     * @param string $parentId Parent comment id, with '0' meaning no reply
     * @return IComment&MockObject
     */
    private function givenComment(string $id, string $verb, string $message, string $actorId = 'alice', string $parentId = '0'): IComment {
        $comment = $this->createMock(IComment::class);
        $comment->method('getId')->willReturn($id);
        $comment->method('getVerb')->willReturn($verb);
        $comment->method('getMessage')->willReturn($message);
        $comment->method('getActorType')->willReturn('users');
        $comment->method('getActorId')->willReturn($actorId);
        $comment->method('getParentId')->willReturn($parentId);
        $comment->method('getCreationDateTime')->willReturn(new DateTimeImmutable('@1600000000'));

        return $comment;
    }
}

/** The chat surface the reader uses, plus the writing calls it must never make. */
interface ReaderChatGateway {
    /** @return array<IComment> */
    public function getHistory(object $room, int $offset, int $limit, bool $includeLastKnown, int $threadId = 0): array;

    public function sendMessage(object $room, string $message): void;

    public function addSystemMessage(object $room, string $message, ?int $timestamp = null): void;

    public function getParentComment(object $room, string $parentId): object;
}

/** The participant surface the reader uses, plus the read-marking call it must never make. */
interface ReaderParticipantGateway {
    public function getAttendee(): object;

    public function markAsRead(): void;

    public function ensureOneToOneRoomIsFilled(object $room): void;
}

/** Room double with the values the reader normalizes. */
final class ReaderRoomStub {
    public function __construct(
        private string $token,
        private int $type = 2,
        private ?int $lastActivity = 0,
    ) {}

    public function getToken(): string {
        return $this->token;
    }

    public function getType(): int {
        return $this->type;
    }

    public function getDisplayName(string $userId, bool $withFallback = true): string {
        return 'Sala nova';
    }

    public function getLastActivity(): ?DateTimeImmutable {
        return $this->lastActivity === null ? null : new DateTimeImmutable('@' . $this->lastActivity);
    }
}

/** Participant double whose attendee carries the counters the reader reports. */
final class ReaderParticipantStub implements ReaderParticipantGateway {
    public function __construct(
        private int $unread = 0,
        private int $participantType = 3,
        private bool $archived = false,
    ) {}

    public function getAttendee(): object {
        return new class ($this->unread, $this->participantType, $this->archived) {
            public function __construct(private int $unread, private int $participantType, private bool $archived) {}

            public function getUnreadMessages(): int {
                return $this->unread;
            }

            public function getParticipantType(): int {
                return $this->participantType;
            }

            public function isArchived(): bool {
                return $this->archived;
            }
        };
    }

    public function markAsRead(): void {
        throw new RuntimeException('Reading must not mark anything as read.');
    }

    public function ensureOneToOneRoomIsFilled(object $room): void {
        throw new RuntimeException('Reading must not fill a one to one room.');
    }
}
