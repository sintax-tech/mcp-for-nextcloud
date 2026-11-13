<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Talk;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Mcp\Modules\Talk\Attachments\AttachmentAccess;
use OCA\Mcp\Modules\Talk\Attachments\AttachmentMessage;
use OCA\Mcp\Modules\Talk\Attachments\FileSharer;
use OCA\Mcp\Modules\Talk\Groups\GroupCreator;
use OCA\Mcp\Modules\Talk\Integration\TalkServices;
use OCA\Mcp\Modules\Talk\Integration\UnreadCounter;
use OCA\Mcp\Modules\Talk\Integration\UnreadRoomView;
use OCA\Mcp\Modules\Talk\Locating\ConversationResolver;
use OCA\Mcp\Modules\Talk\Locating\UserConversationResolver;
use OCA\Mcp\Modules\Talk\Presentation\ActorNames;
use OCA\Mcp\Modules\Talk\Reading\ConversationReader;
use OCA\Mcp\Modules\Talk\ReferenceLinker;
use OCA\Mcp\Modules\Talk\TalkModule;
use OCA\Mcp\Modules\Talk\Writing\ConversationWriter;
use OCA\Mcp\Modules\Talk\Writing\WritePreview;
use OCA\Mcp\Tests\Unit\Access\Visibility\OutputReferenceFilters;
use OCA\Mcp\Tests\Unit\Tools\Talk\TalkTestModule;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every Talk read and every plan, through the real module, resolver, preview and writer, writes nothing in Talk.
 *
 * Each Talk call that persists something (a lobby, a room name, a read marker, a session, a message, a room) is
 * counted before it answers, so a caller that swallows an exception cannot hide it. tearDown requires exactly the
 * writes a test expects: none for a read, an unread read or a plan, and for a confirmed call the writes Talk's own controller makes,
 * the elapsed lobby opened first. The listings are covered the same way by ConversationReaderTest and
 * UnreadConversationsTest.
 */
final class TalkPlansWriteNothingTest extends TestCase {
	/** @var list<string> writes the current test expects, in order */
	private array $expectedWrites = [];
	private PlanRoom $group;
	private TalkModule $module;

	protected function setUp(): void {
		parent::setUp();
		PlanWriteSpy::$calls = [];
		PlanChat::$forwardReads = [];
		$this->group = new PlanRoom('grupo1234', 'Projeto', 2, 2, new DateTimeImmutable('-1 minute'));
		$direct = new PlanRoom('direto1234', 'bob', 1);
		$created = new PlanRoom('direto1234', '["alice","bob"]', 1);
		$this->module = $this->module(['grupo1234' => $this->group, 'direto1234' => $direct], $created);
	}

	protected function tearDown(): void {
		$this->assertSame($this->expectedWrites, PlanWriteSpy::$calls);
		parent::tearDown();
	}

	/** The history behind an elapsed lobby is read without opening the lobby. */
	public function testReadingMessagesWritesNothing(): void {
		$result = $this->module->call(TalkModule::TOOL_READ, ['conversation_token' => 'grupo1234', 'limit' => 5], 'alice');

		$this->assertArrayNotHasKey('isError', $result);
		$this->assertSame(2, $this->group->getLobbyState());
	}

	/** Both pages of the unread read, behind an elapsed lobby, write nothing and never ask Talk to notify. */
	public function testReadingUnreadMessagesWritesNothing(): void {
		$first = $this->module->call(TalkModule::TOOL_READ_UNREAD, ['conversation_token' => 'grupo1234', 'limit' => 1], 'alice');
		$page = $first['structuredContent'] ?? json_decode($first['content'][0]['text'], true);
		$second = $this->module->call(TalkModule::TOOL_READ_UNREAD, ['conversation_token' => 'grupo1234', 'limit' => 1, 'cursor' => $page['nextCursor']], 'alice');

		$this->assertArrayNotHasKey('isError', $second, json_encode($second) ?: '');
		$this->assertSame(5, $page['lastReadMessage']);
		$this->assertSame([[5, 2, 0, null, false, false, 0], [10, 2, 0, null, false, false, 0]], PlanChat::$forwardReads);
		$this->assertSame(2, $this->group->getLobbyState());
	}

	/**
	 * @return array<string, array{string, array<string, mixed>, string|null}>
	 */
	public static function plans(): array {
		return [
			'reply behind an elapsed lobby' => [TalkModule::TOOL_REPLY, ['conversation_token' => 'grupo1234', 'message' => 'oi', 'reply_to' => 10], 'Projeto'],
			'reply in a legacy one to one' => [TalkModule::TOOL_REPLY, ['conversation_token' => 'direto1234', 'message' => 'oi'], 'Bob Souza'],
			'batch' => [TalkModule::TOOL_SEND_BATCH, ['conversation_token' => 'grupo1234', 'messages' => [['message' => 'a'], ['message' => 'b', 'replyTo' => 10]]], 'Projeto'],
			'attach' => [TalkModule::TOOL_ATTACH, ['conversation_token' => 'grupo1234', 'path' => 'a.pdf'], 'Projeto'],
			'quote' => [TalkModule::TOOL_QUOTE, ['conversation_token' => 'grupo1234', 'attachment_id' => 5], 'Projeto'],
			'direct message' => [TalkModule::TOOL_MESSAGE_USER, ['user' => 'bob', 'message' => 'oi'], null],
			'group' => [TalkModule::TOOL_CREATE_GROUP, ['name' => 'Equipe', 'participants' => ['bob']], null],
		];
	}

	/**
	 * @param array<string, mixed> $arguments
	 */
	#[DataProvider('plans')]
	public function testAPlanWritesNothingAndNamesTheConversationAsTheConfirmedCallWould(string $tool, array $arguments, ?string $name): void {
		$plan = $this->module->preview($tool, $arguments, 'alice');

		$this->assertTrue($plan['requiresConfirmation'] ?? false, json_encode($plan) ?: '');
		if ($name !== null) {
			$this->assertSame($name, $plan['conversation']['displayName']);
		}
		$this->assertSame(2, $this->group->getLobbyState());
	}

	/**
	 * @return array<string, array{string, array<string, mixed>, list<string>}>
	 */
	public static function confirmedWrites(): array {
		$send = ['ParticipantService::ensureOneToOneRoomIsFilled', 'ChatManager::sendMessage'];
		return [
			'reply' => [TalkModule::TOOL_REPLY, ['conversation_token' => 'grupo1234', 'message' => 'oi'], ['RoomService::validateLobbyTimer', ...$send]],
			'batch' => [TalkModule::TOOL_SEND_BATCH, ['conversation_token' => 'grupo1234', 'messages' => [['message' => 'a'], ['message' => 'b']]], ['RoomService::validateLobbyTimer', ...$send, ...$send]],
			// The share itself is a Files double here: only the lobby is Talk's.
			'attach' => [TalkModule::TOOL_ATTACH, ['conversation_token' => 'grupo1234', 'path' => 'a.pdf'], ['RoomService::validateLobbyTimer']],
			'quote' => [TalkModule::TOOL_QUOTE, ['conversation_token' => 'grupo1234', 'attachment_id' => 5], ['RoomService::validateLobbyTimer', 'ChatManager::addSystemMessage']],
			'reply without lobby' => [TalkModule::TOOL_REPLY, ['conversation_token' => 'direto1234', 'message' => 'oi'], $send],
			'direct message' => [TalkModule::TOOL_MESSAGE_USER, ['user' => 'bob', 'message' => 'oi'], ['RoomService::createOneToOneConversation', ...$send]],
		];
	}

	/**
	 * @param array<string, mixed> $arguments
	 * @param list<string> $writes
	 */
	#[DataProvider('confirmedWrites')]
	public function testTheConfirmedCallOpensAnElapsedLobbyBeforeItWrites(string $tool, array $arguments, array $writes): void {
		$this->expectedWrites = $writes;

		$result = $this->module->call($tool, $arguments + ['confirm' => true], 'alice');

		$this->assertArrayNotHasKey('isError', $result, json_encode($result) ?: '');
		$opened = in_array('RoomService::validateLobbyTimer', $writes, true);
		$this->assertSame($opened ? 0 : 2, $this->group->getLobbyState());
	}

	/** The direct conversation the confirmed call opens is named from the pair Talk stored, without writing it. */
	public function testTheConfirmedDirectMessageNamesTheOtherAccount(): void {
		$this->expectedWrites = ['RoomService::createOneToOneConversation', 'ParticipantService::ensureOneToOneRoomIsFilled', 'ChatManager::sendMessage'];

		$result = $this->module->call(TalkModule::TOOL_MESSAGE_USER, ['user' => 'bob', 'message' => 'oi', 'confirm' => true], 'alice');

		$payload = $result['structuredContent'] ?? json_decode($result['content'][0]['text'], true);
		$this->assertSame(['id' => 'bob', 'displayName' => 'Bob Souza'], $payload['user']);
	}

	/**
	 * @param array<string, PlanRoom> $rooms rooms by token
	 * @param PlanRoom $created room Talk answers when the direct conversation is opened
	 */
	private function module(array $rooms, PlanRoom $created): TalkModule {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => in_array($uid, ['alice', 'bob'], true) ? $this->user($uid) : null);
		$users->method('getDisplayName')->willReturnCallback(static fn (string $uid): ?string => $uid === 'bob' ? 'Bob Souza' : null);
		$shares = $this->createMock(IShareManager::class);
		$shares->method('currentUserCanEnumerateTargetUser')->willReturn(true);
		$comment = $this->comment();

		$talk = $this->createMock(TalkServices::class);
		$talk->method('isEnabledFor')->willReturn(true);
		$talk->method('manager')->willReturn(new PlanManager($rooms));
		$talk->method('participantService')->willReturn(new PlanParticipants());
		$talk->method('roomService')->willReturn(new PlanRooms($created));
		$talk->method('chatManager')->willReturn(new PlanChat($comment));
		$talk->method('unreadMarkers')->willReturn(['unreadMigration' => -1, 'unreadFirstMessage' => -2]);
		$talk->method('conversationConstants')->willReturn([
			'chatPermission' => 128, 'lobbyIgnorePermission' => 8, 'readOnly' => 1, 'changelogType' => 4,
			'lobbyNone' => 0, 'actorUsers' => 'users', 'groupType' => 2,
		]);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): \DateTime => new \DateTime('2026-01-02 03:04:05'));
		$attachments = $this->createMock(AttachmentAccess::class);
		$attachments->method('requireRoomShareOf')->willReturn($this->createMock(IShare::class));
		$attachments->method('describe')->willReturn(['attachmentId' => 5, 'name' => 'a.pdf', 'size' => 3, 'mimeType' => 'application/pdf']);
		$sharer = $this->createMock(FileSharer::class);
		$sharer->method('previewFile')->willReturn(['path' => '/a.pdf', 'name' => 'a.pdf', 'size' => 3]);
		$sharer->method('attach')->willReturn(['conversation_token' => 'grupo1234', 'attachmentId' => 5, 'file' => ['path' => '/a.pdf', 'name' => 'a.pdf', 'size' => 3], 'caption' => null, 'captionSent' => null]);

		$views = new UnreadRoomView($users, 1);
		$resolver = new ConversationResolver($talk, $views);
		$writer = new ConversationWriter($talk, $time, new AttachmentMessage(), $attachments);
		$names = new ActorNames($users);
		$userConversations = new UserConversationResolver($talk, $users, $shares, $views);
		$reader = new ConversationReader($talk, $resolver, $names, OutputReferenceFilters::inactive($this), new UnreadCounter($users), $views);

		return TalkTestModule::build($talk, $reader, $resolver, $writer, $sharer, new WritePreview($writer, $sharer, $attachments, $names),
			$userConversations, $this->createMock(GroupCreator::class), $this->createMock(ReferenceLinker::class), $this->createMock(LoggerInterface::class));
	}

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	private function comment(): IComment {
		$comment = $this->createMock(IComment::class);
		$comment->method('getId')->willReturn('10');
		$comment->method('getMessage')->willReturn('oi');
		$comment->method('getVerb')->willReturn('comment');
		$comment->method('getActorId')->willReturn('bob');
		$comment->method('getActorType')->willReturn('users');
		$comment->method('getParentId')->willReturn('0');
		$comment->method('getCreationDateTime')->willReturn(new \DateTime('2026-01-01 00:00:00'));
		return $comment;
	}
}

/** Counts every Talk call that would persist something, in the order it was made. */
final class PlanWriteSpy {
	/** @var list<string> */
	public static array $calls = [];
}

/** OCA\Talk\Room: getters read loaded values; getName/getDisplayName/setName persist a name in Talk 24/25. */
final class PlanRoom {
	public function __construct(
		private string $token,
		private string $name,
		private int $type,
		public int $lobby = 0,
		private ?DateTimeInterface $timer = null,
	) {}

	public function getToken(): string {
		return $this->token;
	}

	public function getId(): int {
		return 1;
	}

	public function getType(): int {
		return $this->type;
	}

	public function getObjectType(): string {
		return '';
	}

	public function getReadOnly(): int {
		return 0;
	}

	public function isFederatedConversation(): bool {
		return false;
	}

	public function getLobbyState(): int {
		return $this->lobby;
	}

	public function getLobbyTimer(): ?DateTimeInterface {
		return $this->timer;
	}

	public function getName(): string {
		PlanWriteSpy::$calls[] = 'Room::getName';
		return $this->name;
	}

	public function getDisplayName(string $userId): string {
		PlanWriteSpy::$calls[] = 'Room::getDisplayName';
		return $this->name;
	}

	public function setName(string $name): void {
		PlanWriteSpy::$calls[] = 'Room::setName';
	}
}

/** OCA\Talk\Participant of alice, with the chat permission and no lobby bypass. */
final class PlanParticipant {
	public function getPermissions(): int {
		return 128;
	}

	public function getAttendee(): PlanAttendee {
		return new PlanAttendee();
	}
}

/** OCA\Talk\Model\Attendee of alice: the marker is read; setting it is counted. */
final class PlanAttendee {
	public function getLastReadMessage(): int {
		return 5;
	}

	public function setLastReadMessage(int $id): void {
		PlanWriteSpy::$calls[] = 'Attendee::setLastReadMessage';
	}
}

/** OCA\Talk\Manager: the room lookup, a read. */
final class PlanManager {
	/** @param array<string, PlanRoom> $rooms */
	public function __construct(private array $rooms) {}

	public function getRoomForUserByToken(string $token, ?string $userId, ?string $sessionId = null): object {
		return $this->rooms[$token] ?? throw new RuntimeException('Room not found');
	}
}

/** OCA\Talk\Service\ParticipantService: participant and account reads; marker, session and pair fills counted. */
final class PlanParticipants {
	public function getParticipant(object $room, ?string $userId, mixed $sessionId = null): object {
		return new PlanParticipant();
	}

	/** @return list<string> */
	public function getParticipantUserIds(object $room): array {
		return ['alice', 'bob'];
	}

	public function updateLastReadMessage(object $participant, int $lastReadMessage): void {
		PlanWriteSpy::$calls[] = 'ParticipantService::updateLastReadMessage';
	}

	public function joinRoom(mixed ...$arguments): object {
		PlanWriteSpy::$calls[] = 'ParticipantService::joinRoom';
		return new PlanParticipant();
	}

	public function ensureOneToOneRoomIsFilled(object $room, ?string $enforceUserId = null): void {
		PlanWriteSpy::$calls[] = 'ParticipantService::ensureOneToOneRoomIsFilled';
	}
}

/** OCA\Talk\Service\RoomService: every method here persists something. */
final class PlanRooms {
	public function __construct(private PlanRoom $created) {}

	public function validateLobbyTimer(object $room): void {
		PlanWriteSpy::$calls[] = 'RoomService::validateLobbyTimer';
		$room->lobby = 0;
	}

	public function setLobby(mixed ...$arguments): void {
		PlanWriteSpy::$calls[] = 'RoomService::setLobby';
	}

	public function setName(mixed ...$arguments): void {
		PlanWriteSpy::$calls[] = 'RoomService::setName';
	}

	public function resolveOneToOneName(mixed ...$arguments): void {
		PlanWriteSpy::$calls[] = 'RoomService::resolveOneToOneName';
	}

	public function createOneToOneConversation(IUser $actor, IUser $target): object {
		PlanWriteSpy::$calls[] = 'RoomService::createOneToOneConversation';
		return $this->created;
	}
}

/** OCA\Talk\Chat\ChatManager: history and parent are reads; messages are counted. */
final class PlanChat {
	/** @var list<array{int, int, int, mixed, bool, bool, int}> arguments of each forward read */
	public static array $forwardReads = [];

	public function __construct(private IComment $comment) {}

	/** @return list<IComment> */
	public function waitForNewMessages(object $room, int $offset, int $limit, int $timeout, ?IUser $user, bool $includeLastKnown, bool $markNotificationsAsRead = true, int $threadId = 0): array {
		self::$forwardReads[] = [$offset, $limit, $timeout, $user, $includeLastKnown, $markNotificationsAsRead, $threadId];
		if ($markNotificationsAsRead && $user !== null) {
			PlanWriteSpy::$calls[] = 'Notifier::markMentionNotificationsRead';
		}
		// Message 10 and one more, so the first page carries a cursor.
		return $offset < 10 ? [$this->comment, $this->comment] : [];
	}

	/** @return list<IComment> */
	public function getHistory(object $room, int $offset, int $limit, bool $includeLastKnown): array {
		return [$this->comment];
	}

	public function getParentComment(object $room, string $parentId): IComment {
		return $this->comment;
	}

	public function getComment(object $room, string $messageId): IComment {
		return $this->comment;
	}

	public function sendMessage(mixed ...$arguments): IComment {
		PlanWriteSpy::$calls[] = 'ChatManager::sendMessage';
		return $this->comment;
	}

	public function addSystemMessage(mixed ...$arguments): IComment {
		PlanWriteSpy::$calls[] = 'ChatManager::addSystemMessage';
		return $this->comment;
	}
}
