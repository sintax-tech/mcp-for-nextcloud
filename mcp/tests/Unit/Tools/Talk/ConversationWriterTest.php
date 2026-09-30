<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Talk;

use DateTime;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Mcp\Tools\Talk\AttachmentMessage;
use OCA\Mcp\Tools\Talk\Conversation;
use OCA\Mcp\Tools\Talk\ConversationAccessException;
use OCA\Mcp\Tools\Talk\ConversationWriter;
use OCA\Mcp\Tools\Talk\Messages;
use OCA\Mcp\Tools\Talk\TalkServices;
use OCA\Mcp\Tools\Talk\TalkUnavailableException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\Comments\NotFoundException as CommentNotFoundException;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ConversationWriterTest extends TestCase {
    private TalkServices&MockObject $talkServices;
    private ITimeFactory&MockObject $timeFactory;
    private IShareManager&MockObject $shareManager;
    private WriterParticipantGateway&MockObject $participantService;
    private ConversationWriter $writer;
    private WriterRoomStub $room;

    protected function setUp(): void {
        parent::setUp();
        $this->talkServices = $this->createMock(TalkServices::class);
        $this->timeFactory = $this->createMock(ITimeFactory::class);
        $this->shareManager = $this->createMock(IShareManager::class);
        $this->writer = new ConversationWriter(
            $this->talkServices,
            $this->timeFactory,
            new AttachmentMessage(),
            $this->shareManager,
        );
        $this->room = new WriterRoomStub('abcd');
        $this->participantService = $this->createMock(WriterParticipantGateway::class);
        $this->talkServices->method('participantService')->willReturn($this->participantService);
        $this->talkServices->method('conversationConstants')->willReturn([
            'chatPermission' => 512,
            'lobbyIgnorePermission' => 3,
            'readOnly' => 1,
            'changelogType' => 2,
            'lobbyNone' => 11,
            'actorUsers' => 'personas',
        ]);
        $this->timeFactory->method('getDateTime')
            ->willReturn(new DateTime('2026-01-02 03:04:05', new DateTimeZone('UTC')));
    }

    public function testReplyFillsTheOneToOneRoomAndSendsAsTheUser(): void {
        $chatManager = $this->givenChatManager();
        $this->participantService->expects($this->once())->method('ensureOneToOneRoomIsFilled')->with($this->room);
        $chatManager->expects($this->once())
            ->method('sendMessage')
            ->with(
                $this->room,
                $this->anything(),
                'personas',
                'alice',
                'bom dia',
                $this->callback(static function (DateTime $when): bool {
                    return $when->format('Y-m-d H:i:s') === '2026-01-02 03:04:05'
                        && $when->getTimezone()->getName() === 'UTC';
                }),
                null,
            )
            ->willReturn($this->givenSentComment('55'));
        $this->shareManager->expects($this->never())->method('createShare');

        $result = $this->writer->reply($this->givenConversation(), 'alice', 'bom dia');

        $this->assertSame(['conversation_token' => 'abcd', 'messageId' => 55], $result);
    }

    public function testReplyTrimsTheBodyAndRejectsABlankOne(): void {
        $chatManager = $this->givenChatManager();
        $chatManager->expects($this->once())->method('sendMessage')->with(
            $this->anything(),
            $this->anything(),
            $this->anything(),
            $this->anything(),
            'bom dia',
        )->willReturn($this->givenSentComment('56'));
        $this->writer->reply($this->givenConversation(), 'alice', "  bom dia \n");

        $this->talkServices = $this->createMock(TalkServices::class);
        $this->talkServices->expects($this->never())->method('chatManager');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Messages::EMPTY_MESSAGE);
        $this->writer->reply($this->givenConversation(), 'alice', "   \n ");
    }

    public function testMessageAboveTheLimitIsAnArgumentError(): void {
        $this->talkServices->expects($this->never())->method('chatManager');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Messages::MESSAGE_TOO_LONG);
        $this->writer->reply(
            $this->givenConversation(),
            'alice',
            str_repeat('a', ConversationWriter::MAX_MESSAGE_LENGTH + 1),
        );
    }

    public function testNonPositiveQuotedIdIsAnArgumentError(): void {
        $chatManager = $this->givenChatManager();
        $chatManager->expects($this->never())->method('getParentComment');
        $chatManager->expects($this->never())->method('sendMessage');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Messages::INVALID_IDENTIFIER);
        $this->writer->reply($this->givenConversation(), 'alice', 'oi', 0);
    }

    public function testQuotedMessageIsResolvedThroughTheChatManagerOfTheConversation(): void {
        $parent = new WriterCommentStub('42');
        $chatManager = $this->givenChatManager();
        $chatManager->expects($this->once())->method('getParentComment')->with($this->room, '42')->willReturn($parent);
        $chatManager->expects($this->once())->method('sendMessage')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                'concordo',
                $this->anything(),
                $parent,
            )
            ->willReturn($this->givenSentComment('57'));

        $this->writer->reply($this->givenConversation(), 'alice', 'concordo', 42);
    }

    public function testQuotedMessageThatDoesNotExistStopsTheReply(): void {
        $chatManager = $this->givenChatManager();
        $chatManager->method('getParentComment')
            ->willThrowException(new CommentNotFoundException('no such comment'));
        $chatManager->expects($this->never())->method('sendMessage');

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(Messages::REPLY_TARGET_NOT_FOUND);
        $this->writer->reply($this->givenConversation(), 'alice', 'concordo', 42);
    }

    public function testQuotedIdFromAnotherConversationLooksExactlyLikeAMissingOne(): void {
        $chatManager = $this->givenChatManager();
        $chatManager->method('getParentComment')
            ->willThrowException(new RuntimeException('Comment 42 belongs to another room'));
        $chatManager->expects($this->never())->method('sendMessage');

        try {
            $this->writer->reply($this->givenConversation(), 'alice', 'concordo', 42);
            $this->fail('A quoted id of another conversation must be refused.');
        } catch (ConversationAccessException $e) {
            $this->assertSame(Messages::REPLY_TARGET_NOT_FOUND, $e->getMessage());
            $this->assertStringNotContainsString('another room', $e->getMessage());
        }
    }

    public function testUnavailableSpreedIsNotDisguisedAsARefusal(): void {
        // The unavailable answer must survive: answering "not permitted" would send the user after a grant problem.
        $services = $this->createMock(TalkServices::class);
        $services->method('participantService')
            ->willThrowException(new TalkUnavailableException(Messages::TALK_UNAVAILABLE));
        $writer = new ConversationWriter($services, $this->timeFactory, new AttachmentMessage(), $this->shareManager);

        $this->expectException(TalkUnavailableException::class);
        $writer->reply($this->givenConversation(), 'alice', 'bom dia');
    }

    public function testSendFailureBecomesAGenericRefusal(): void {
        $chatManager = $this->givenChatManager();
        $chatManager->method('sendMessage')
            ->willThrowException(new RuntimeException('Database connection lost at 10.0.0.9'));

        try {
            $this->writer->reply($this->givenConversation(), 'alice', 'bom dia');
            $this->fail('A failed send must become a refusal.');
        } catch (ConversationAccessException $e) {
            $this->assertSame(Messages::MESSAGE_NOT_SENT, $e->getMessage());
            $this->assertStringNotContainsString('10.0.0.9', $e->getMessage());
        }
    }

    public function testQuotePublishesTheEnvelopeOnceAndCreatesNoShare(): void {
        $chatManager = $this->givenChatManager();
        $chatManager->expects($this->once())
            ->method('addSystemMessage')
            ->with(
                $this->room,
                $this->anything(),
                'personas',
                'alice',
                $this->callback(static function (string $envelope): bool {
                    $decoded = json_decode($envelope, true);

                    return $decoded['message'] === 'file_shared'
                        && $decoded['parameters']['share'] === '77'
                        && $decoded['parameters']['metaData']['caption'] === 'a figura';
                }),
                $this->anything(),
                true,
            )
            ->willReturn($this->givenSentComment('88'));
        $this->shareManager->method('getShareById')
            ->with('77', 'alice')
            ->willReturn($this->givenRoomShare(IShare::TYPE_ROOM, 'abcd'));
        // The card is a message, never a new share: a share here would publish the attachment twice.
        $this->shareManager->expects($this->never())->method('createShare');
        $this->shareManager->expects($this->never())->method('newShare');

        $result = $this->writer->quoteAttachment($this->givenConversation(), 'alice', 77, 'a figura');

        $this->assertSame([
            'conversation_token' => 'abcd',
            'messageId' => 88,
            'attachmentId' => 77,
        ], $result);
    }

    public function testQuoteRefusesAnAttachmentOfAnotherConversation(): void {
        $this->shareManager->method('getShareById')->willReturn($this->givenRoomShare(IShare::TYPE_ROOM, 'zzzz'));
        $chatManager = $this->givenChatManager();
        $chatManager->expects($this->never())->method('addSystemMessage');

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(Messages::ATTACHMENT_NOT_FOUND);
        $this->writer->quoteAttachment($this->givenConversation(), 'alice', 77);
    }

    public function testQuoteRefusesAShareThatIsNotOfTheConversationKind(): void {
        $this->shareManager->method('getShareById')->willReturn($this->givenRoomShare(IShare::TYPE_LINK, 'abcd'));
        $chatManager = $this->givenChatManager();
        $chatManager->expects($this->never())->method('addSystemMessage');

        $this->expectException(ConversationAccessException::class);
        $this->expectExceptionMessage(Messages::ATTACHMENT_NOT_FOUND);
        $this->writer->quoteAttachment($this->givenConversation(), 'alice', 77);
    }

    public function testQuoteOfSomethingTheUserCannotSeeIsRefusedBeforeAnyWrite(): void {
        $this->shareManager->method('getShareById')
            ->willThrowException(new RuntimeException('Share 77 is not visible to alice'));
        $chatManager = $this->givenChatManager();
        $chatManager->expects($this->never())->method('addSystemMessage');

        try {
            $this->writer->quoteAttachment($this->givenConversation(), 'alice', 77);
            $this->fail('An attachment the user cannot see must be refused.');
        } catch (ConversationAccessException $e) {
            $this->assertSame(Messages::ATTACHMENT_NOT_FOUND, $e->getMessage());
            $this->assertStringNotContainsString('not visible', $e->getMessage());
        }
    }

    public function testQuoteWithoutCaptionPublishesNoMetaData(): void {
        $chatManager = $this->givenChatManager();
        $chatManager->expects($this->once())
            ->method('addSystemMessage')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(static function (string $envelope): bool {
                    return !array_key_exists('metaData', json_decode($envelope, true)['parameters']);
                }),
                $this->anything(),
                true,
            )
            ->willReturn($this->givenSentComment('89'));
        $this->shareManager->method('getShareById')->willReturn($this->givenRoomShare(IShare::TYPE_ROOM, 'abcd'));

        $this->writer->quoteAttachment($this->givenConversation(), 'alice', 77);
    }

    private function givenConversation(): Conversation {
        return new Conversation($this->room, new WriterParticipantStub());
    }

    private function givenChatManager(): WriterChatGateway&MockObject {
        $chatManager = $this->createMock(WriterChatGateway::class);
        $this->talkServices->method('chatManager')->willReturn($chatManager);

        return $chatManager;
    }

    private function givenSentComment(string $id): IComment&MockObject {
        $comment = $this->createMock(IComment::class);
        $comment->method('getId')->willReturn($id);

        return $comment;
    }

    private function givenRoomShare(int $shareType, string $sharedWith): IShare&MockObject {
        $share = $this->createMock(IShare::class);
        $share->method('getShareType')->willReturn($shareType);
        $share->method('getSharedWith')->willReturn($sharedWith);

        return $share;
    }
}

/** The chat surface the writer uses. */
interface WriterChatGateway {
    public function getParentComment(object $room, string $parentId): object;

    public function sendMessage(
        object $room,
        object $participant,
        string $actorType,
        string $actorId,
        string $message,
        DateTime $when,
        ?object $parent = null,
    ): object;

    public function addSystemMessage(
        object $room,
        object $participant,
        string $actorType,
        string $actorId,
        string $message,
        DateTime $when,
        bool $systemMessage = false,
    ): object;
}

/** The participant surface the writer uses. */
interface WriterParticipantGateway {
    public function ensureOneToOneRoomIsFilled(object $room): void;
}

final class WriterRoomStub {
    public function __construct(private string $token) {}

    public function getToken(): string {
        return $this->token;
    }
}

final class WriterParticipantStub {
    public function getPermissions(): int {
        return 128;
    }
}

/** Comment double identified only by its id, which is all the writer passes along. */
final class WriterCommentStub {
    public function __construct(private string $id) {}

    public function getId(): string {
        return $this->id;
    }
}
