<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Talk;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Talk\Failures\ConversationAccessException;
use OCA\Mcp\Modules\Talk\Integration\TalkServices;
use OCA\Mcp\Modules\Talk\Integration\UnreadCounter;
use OCA\Mcp\Modules\Talk\Integration\UnreadRoomView;
use OCA\Mcp\Modules\Talk\Locating\ConversationResolver;
use OCA\Mcp\Modules\Talk\Presentation\ActorNames;
use OCA\Mcp\Modules\Talk\Presentation\Messages;
use OCA\Mcp\Modules\Talk\Presentation\PageMessages;
use OCA\Mcp\Modules\Talk\Reading\ConversationReader;
use OCA\Mcp\Modules\Talk\Reading\MessageCursor;
use OCA\Mcp\Modules\Talk\Reading\UnreadMessages;
use OCA\Mcp\Modules\Talk\TalkModule;
use OCA\Mcp\Tests\Unit\Access\Visibility\OutputReferenceFilters;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\Comments\IComment;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Reading after the read marker, against the real resolver, reader, counter and cursor.
 *
 * This process cannot load OCA\Talk, so doubles stand in for the room, the participant and the chat manager. Every
 * call that would persist something (a marker, a session, a lobby, a name, a mention notification) is counted before
 * it answers, and tearDown requires none. The chat double records the exact arguments of each forward read.
 */
final class UnreadMessagesTest extends TestCase {
	private MarkerRoom $room;
	private MarkerAttendee $attendee;
	private MarkerParticipant $participant;
	private MarkerChat $chat;
	private UnreadMessages $unread;
	private MessageCursor $cursor;

	protected function setUp(): void {
		parent::setUp();
		MarkerWriteSpy::$calls = [];
		$this->room = new MarkerRoom('sala1234', 'Projeto');
		$this->attendee = new MarkerAttendee(20);
		$this->participant = new MarkerParticipant($this->attendee);
		$this->chat = new MarkerChat(range(11, 40), $this->comment(...));

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => $uid === 'alice' ? $this->createMock(IUser::class) : null);
		$users->method('getDisplayName')->willReturnArgument(0);
		$talk = $this->createMock(TalkServices::class);
		$talk->expects($this->never())->method('roomService');
		$talk->method('manager')->willReturn(new MarkerManager($this->room));
		$talk->method('participantService')->willReturn(new MarkerParticipants($this->participant));
		$talk->method('chatManager')->willReturn($this->chat);
		$talk->method('unreadMarkers')->willReturn(['unreadMigration' => -1, 'unreadFirstMessage' => -2]);
		$talk->method('conversationConstants')->willReturn([
			'chatPermission' => 128, 'lobbyIgnorePermission' => 8, 'readOnly' => 1, 'changelogType' => 4,
			'lobbyNone' => 0, 'actorUsers' => 'users', 'groupType' => 2,
		]);

		$views = new UnreadRoomView($users, 1);
		$resolver = new ConversationResolver($talk, $views);
		$reader = new ConversationReader($talk, $resolver, new ActorNames($users), OutputReferenceFilters::inactive($this), new UnreadCounter($users), $views);
		$config = new InMemoryConfig();
		$config->system['secret'] = 'talk-unread-messages-secret';
		$this->cursor = new MessageCursor(new PlanState($config->mock($this)));
		$this->unread = new UnreadMessages($talk, $resolver, $reader, new UnreadCounter($users), $this->cursor);
	}

	protected function tearDown(): void {
		$this->assertSame([], MarkerWriteSpy::$calls);
		parent::tearDown();
	}

	public function testTheFirstPageStartsAfterTheMarkerWithoutWaitingOrMarkingAnything(): void {
		$page = $this->unread->read('alice', 'sala1234', 5, null);

		$this->assertSame(['token' => 'sala1234', 'displayName' => 'Projeto'], $page['conversation']);
		$this->assertSame(20, $page['lastReadMessage']);
		$this->assertSame(['21', '22', '23', '24', '25'], array_column($page['messages'], 'id'));
		$this->assertSame('text 21', $page['messages'][0]['text']);
		$this->assertNotNull($page['nextCursor']);
		// offset, limit + 1, timeout 0, no IUser, includeLastKnown false, markNotificationsAsRead false, no thread.
		$this->assertSame([[20, 6, 0, null, false, false, 0]], $this->chat->reads);
	}

	public function testAMarkerMovedByAnotherClientNeitherSkipsNorRepeatsMessages(): void {
		$first = $this->unread->read('alice', 'sala1234', 5, null);
		// Another client reads the whole conversation meanwhile.
		$this->attendee->lastRead = 40;

		$second = $this->unread->read('alice', 'sala1234', 5, $first['nextCursor']);

		$this->assertSame(['26', '27', '28', '29', '30'], array_column($second['messages'], 'id'));
		$this->assertSame(20, $second['lastReadMessage']);
		$this->assertSame(1, $this->attendee->markerReads);
		$this->assertSame(25, $this->chat->reads[1][0]);
	}

	public function testTheLastPageHasNoCursorAndEveryMessageIsReturnedOnce(): void {
		$ids = [];
		$cursor = null;
		$pages = 0;
		do {
			$page = $this->unread->read('alice', 'sala1234', 7, $cursor);
			$ids = [...$ids, ...array_column($page['messages'], 'id')];
			$cursor = $page['nextCursor'];
			$pages++;
		} while ($cursor !== null);

		$this->assertSame(3, $pages);
		$this->assertSame(array_map('strval', range(21, 40)), $ids);
	}

	public function testAMessageArrivingWhilePagingShowsUpOnALaterPage(): void {
		$first = $this->unread->read('alice', 'sala1234', 10, null);
		$this->chat->ids[] = 41;

		$second = $this->unread->read('alice', 'sala1234', 20, $first['nextCursor']);

		$this->assertSame(array_map('strval', range(31, 41)), array_column($second['messages'], 'id'));
		$this->assertNull($second['nextCursor']);
	}

	/**
	 * @return array<string, array{int}>
	 */
	public static function startMarkers(): array {
		return ['never read' => [0], 'marked unread from the first message' => [-2]];
	}

	#[DataProvider('startMarkers')]
	public function testNoKnownReadStartsAtTheOldestMessage(int $marker): void {
		$this->attendee->lastRead = $marker;

		$page = $this->unread->read('alice', 'sala1234', 3, null);

		$this->assertSame(0, $page['lastReadMessage']);
		$this->assertSame(['11', '12', '13'], array_column($page['messages'], 'id'));
		$this->assertSame(0, $this->chat->reads[0][0]);
	}

	public function testALegacyMarkerIsConvertedInMemoryOnly(): void {
		$this->attendee->lastRead = -1;
		$this->chat->legacy = 35;

		$page = $this->unread->read('alice', 'sala1234', 50, null);

		$this->assertSame(35, $page['lastReadMessage']);
		$this->assertSame(['36', '37', '38', '39', '40'], array_column($page['messages'], 'id'));
		$this->assertSame(-1, $this->attendee->lastRead);
	}

	public function testAnUnreadableMarkerRefusesInsteadOfGuessingAStart(): void {
		$this->attendee->lastRead = -1;
		$this->chat->legacy = null;

		$this->expectExceptionObject(new ConversationAccessException(PageMessages::unreadableMarker()));
		$this->unread->read('alice', 'sala1234', 5, null);
	}

	public function testAFederatedConversationIsRefusedBeforeAnyMessageIsRead(): void {
		$this->room->federated = true;

		try {
			$this->unread->read('alice', 'sala1234', 5, null);
			$this->fail('A federated conversation must be refused.');
		} catch (ConversationAccessException $e) {
			$this->assertSame(PageMessages::federatedUnread(), $e->getMessage());
		}
		$this->assertSame([], $this->chat->reads);
	}

	public function testTheLobbyIsCheckedAgainOnEveryPageWithoutOpeningIt(): void {
		$first = $this->unread->read('alice', 'sala1234', 5, null);
		$this->room->lobby = 1;
		$this->room->timer = new DateTimeImmutable('+1 hour');

		try {
			$this->unread->read('alice', 'sala1234', 5, $first['nextCursor']);
			$this->fail('A closed lobby must refuse the next page.');
		} catch (ConversationAccessException $e) {
			$this->assertSame(Messages::conversationNotFound(), $e->getMessage());
		}
		$this->assertCount(1, $this->chat->reads);
		$this->assertSame(1, $this->room->lobby);
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function foreignCursors(): array {
		return [
			'another reader' => ['bob', TalkModule::TOOL_READ_UNREAD, 'sala1234'],
			'another tool' => ['alice', TalkModule::TOOL_LIST_UNREAD, 'sala1234'],
			'another conversation' => ['alice', TalkModule::TOOL_READ_UNREAD, 'outra1234'],
		];
	}

	#[DataProvider('foreignCursors')]
	public function testACursorIssuedForAnotherReaderToolOrConversationIsRefused(string $uid, string $tool, string $token): void {
		$cursor = $this->cursor->next($token, 25, 20, $tool, $uid);

		$this->expectExceptionObject(new InvalidArgumentException(PageMessages::invalidCursor()));
		try {
			$this->unread->read('alice', 'sala1234', 5, $cursor);
		} finally {
			$this->assertSame([], $this->chat->reads);
		}
	}

	public function testAnAlteredCursorIsRefused(): void {
		$cursor = $this->cursor->next('sala1234', 25, 20, TalkModule::TOOL_READ_UNREAD, 'alice');
		[$format, , $mac] = explode('.', $cursor);
		$forged = $format . '.' . rtrim(strtr(base64_encode('{"t":"sala1234","m":10,"b":0}'), '+/', '-_'), '=') . '.' . $mac;

		$this->expectExceptionObject(new InvalidArgumentException(PageMessages::invalidCursor()));
		$this->unread->read('alice', 'sala1234', 5, $forged);
	}

	private function comment(int $id): IComment {
		$comment = $this->createMock(IComment::class);
		$comment->method('getId')->willReturn((string)$id);
		$comment->method('getMessage')->willReturn('text ' . $id);
		$comment->method('getVerb')->willReturn('comment');
		$comment->method('getActorId')->willReturn('bob');
		$comment->method('getActorType')->willReturn('users');
		$comment->method('getParentId')->willReturn('0');
		$comment->method('getCreationDateTime')->willReturn(new \DateTime('2026-01-01 00:00:00'));
		return $comment;
	}

	public function testALimitOutOfRangeIsRefusedBeforeTalkIsAsked(): void {
		$this->expectExceptionObject(new InvalidArgumentException(Messages::invalidLimit()));
		try {
			$this->unread->read('alice', 'sala1234', 201, null);
		} finally {
			$this->assertSame([], $this->chat->reads);
		}
	}
}

/** Counts every Talk call that would persist something, in the order it was made. */
final class MarkerWriteSpy {
	/** @var list<string> */
	public static array $calls = [];
}

/** OCA\Talk\Room: getters read loaded values; name resolution persists in Talk 24/25. */
final class MarkerRoom {
	public bool $federated = false;
	public int $lobby = 0;
	public ?DateTimeInterface $timer = null;

	public function __construct(private string $token, private string $name) {}

	public function getToken(): string {
		return $this->token;
	}

	public function getId(): int {
		return 7;
	}

	public function getType(): int {
		return 2;
	}

	public function getObjectType(): string {
		return '';
	}

	public function isFederatedConversation(): bool {
		return $this->federated;
	}

	public function getLobbyState(): int {
		return $this->lobby;
	}

	public function getLobbyTimer(): ?DateTimeInterface {
		return $this->timer;
	}

	public function getDisplayName(string $userId): string {
		MarkerWriteSpy::$calls[] = 'Room::getDisplayName';
		return $this->name;
	}

	public function setLobby(mixed ...$arguments): void {
		MarkerWriteSpy::$calls[] = 'Room::setLobby';
	}
}

/** OCA\Talk\Model\Attendee: the marker is read; setting it is a write. */
final class MarkerAttendee {
	public int $markerReads = 0;

	public function __construct(public int $lastRead) {}

	public function getLastReadMessage(): int {
		$this->markerReads++;
		return $this->lastRead;
	}

	public function setLastReadMessage(int $id): void {
		MarkerWriteSpy::$calls[] = 'Attendee::setLastReadMessage';
	}

	public function setUnreadMessages(int $count): void {
		MarkerWriteSpy::$calls[] = 'Attendee::setUnreadMessages';
	}
}

/** OCA\Talk\Participant of alice, with the chat permission and no lobby bypass. */
final class MarkerParticipant {
	public function __construct(private MarkerAttendee $attendee) {}

	public function getPermissions(): int {
		return 128;
	}

	public function getAttendee(): MarkerAttendee {
		return $this->attendee;
	}
}

/** OCA\Talk\Manager: the room lookup, a read. */
final class MarkerManager {
	public function __construct(private MarkerRoom $room) {}

	public function getRoomForUserByToken(string $token, ?string $userId): object {
		return $token === $this->room->getToken() ? $this->room : throw new RuntimeException('Room not found');
	}
}

/** OCA\Talk\Service\ParticipantService: participant lookup; marker and session updates counted. */
final class MarkerParticipants {
	public function __construct(private MarkerParticipant $participant) {}

	public function getParticipant(object $room, ?string $userId, mixed $sessionId = null): object {
		if ($sessionId !== false) {
			MarkerWriteSpy::$calls[] = 'ParticipantService::getParticipant with a session';
		}
		return $this->participant;
	}

	public function updateLastReadMessage(object $participant, int $lastReadMessage): void {
		MarkerWriteSpy::$calls[] = 'ParticipantService::updateLastReadMessage';
	}

	public function updateLastPing(mixed ...$arguments): void {
		MarkerWriteSpy::$calls[] = 'SessionService::updateLastPing';
	}
}

/** OCA\Talk\Chat\ChatManager: the forward read and the legacy marker; mention notifications counted. */
final class MarkerChat {
	/** @var list<array{int, int, int, mixed, bool, bool, int}> */
	public array $reads = [];
	public ?int $legacy = null;

	/**
	 * @param list<int> $ids every comment id of the room, ascending
	 * @param \Closure(int): IComment $comment builds the comment of one id
	 */
	public function __construct(public array $ids, private \Closure $comment) {}

	/** @return list<IComment> */
	public function waitForNewMessages(object $room, int $offset, int $limit, int $timeout, ?IUser $user, bool $includeLastKnown, bool $markNotificationsAsRead = true, int $threadId = 0): array {
		$this->reads[] = [$offset, $limit, $timeout, $user, $includeLastKnown, $markNotificationsAsRead, $threadId];
		if ($markNotificationsAsRead && $user !== null) {
			MarkerWriteSpy::$calls[] = 'Notifier::markMentionNotificationsRead';
		}
		$after = array_values(array_filter($this->ids, static fn (int $id): bool => $id > $offset));
		return array_map($this->comment, array_slice($after, 0, $limit));
	}

	public function getLastReadMessageFromLegacy(object $room, IUser $user): int {
		return $this->legacy ?? throw new RuntimeException('No legacy read mark');
	}
}
