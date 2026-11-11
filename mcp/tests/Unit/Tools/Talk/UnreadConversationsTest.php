<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Talk;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Mcp\Access\Grants\GrantCatalog;
use OCA\Mcp\Modules\Talk\Failures\TalkErrors;
use OCA\Mcp\Modules\Talk\Failures\TalkUnavailableException;
use OCA\Mcp\Modules\Talk\Integration\TalkServices;
use OCA\Mcp\Modules\Talk\Locating\Conversation;
use OCA\Mcp\Modules\Talk\Locating\ConversationResolver;
use OCA\Mcp\Modules\Talk\Presentation\Messages;
use OCA\Mcp\Modules\Talk\Reading\ListUnreadHandler;
use OCA\Mcp\Modules\Talk\Reading\UnreadConversations;
use OCA\Mcp\Modules\Talk\TalkModule;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Mcp\Tools\UnknownToolException;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/** The unread listing: only conversations with unread messages, never a lobby counter, never a write. */
final class UnreadConversationsTest extends TestCase {
	private TalkServices&MockObject $services;
	private ConversationResolver&MockObject $resolver;
	private UnreadConversations $listing;
	private UnreadParticipantServiceStub $participants;

	protected function setUp(): void {
		parent::setUp();
		$this->services = $this->createMock(TalkServices::class);
		$this->resolver = $this->createMock(ConversationResolver::class);
		$this->services->expects($this->never())->method('chatManager');
		$this->services->expects($this->never())->method('roomService');
		$this->listing = new UnreadConversations($this->services, $this->resolver);
	}

	public function testOnlyConversationsWithUnreadMessagesAreReturned(): void {
		$open = $this->room('open', 300);
		$quiet = $this->room('quiet', 200);
		$archived = $this->room('kept', 100);
		$this->givenRooms([
			[$open, $this->participant(4, 12, archived: false)],
			[$quiet, $this->participant(0, 9)],
			[$archived, $this->participant(2, 0, archived: true)],
		]);
		$this->resolver->method('keepsOutOfLobby')->willReturn(false);

		$page = $this->listing->list('alice', 50, null);

		$this->assertNull($page['nextCursor']);
		$this->assertSame(['open', 'kept'], array_column($page['conversations'], 'token'));
		$this->assertSame(4, $page['conversations'][0]['unreadMessages']);
		$this->assertSame(12, $page['conversations'][0]['lastReadMessage']);
		$this->assertSame('Name open', $page['conversations'][0]['displayName']);
		$this->assertFalse($page['conversations'][0]['archived']);
		$this->assertSame(300, $page['conversations'][0]['lastActivity']);
		$this->assertTrue($page['conversations'][1]['archived']);
		$this->assertSame(0, $page['conversations'][1]['lastReadMessage']);
		$this->assertFalse($quiet->named);
	}

	public function testAnEmptyUnreadSetReturnsNoConversations(): void {
		$quiet = $this->room('quiet', 10);
		$this->givenRooms([[$quiet, $this->participant(0, 9)]]);
		$this->resolver->expects($this->never())->method('keepsOutOfLobby');

		$page = $this->listing->list('alice', 50, null);

		$this->assertSame([], $page['conversations']);
		$this->assertNull($page['nextCursor']);
		$this->assertFalse($quiet->named);
	}

	public function testALobbyRoomIsOmittedAndItsCounterIsAbsent(): void {
		$open = $this->room('open', 20);
		$barred = $this->room('barred', 90);
		$this->givenRooms([
			[$open, $this->participant(1, 1)],
			[$barred, $this->participant(91, 4)],
		]);
		$this->resolver->method('keepsOutOfLobby')->willReturnCallback(
			static fn (string $userId, Conversation $conversation): bool => $conversation->token() === 'barred',
		);

		$encoded = json_encode($this->listing->list('alice', 50, null), JSON_THROW_ON_ERROR);

		$this->assertStringNotContainsString('barred', $encoded);
		$this->assertStringNotContainsString('91', $encoded);
		$this->assertStringContainsString('open', $encoded);
		$this->assertFalse($barred->named);
	}

	public function testPagesStopAtFiftyAndTheCursorContinuesAfterTheLastRow(): void {
		$rows = [];
		for ($i = 1; $i <= 3; $i++) {
			$rows[] = [$this->room('r' . $i, 300 - $i), $this->participant($i, 10 + $i)];
		}
		// Same activity as r2, later token, so the tie-break puts it after r2.
		$rows[] = [$this->room('r2b', 298), $this->participant(6, 1)];
		$this->givenRooms($rows);
		$this->resolver->method('keepsOutOfLobby')->willReturn(false);

		$first = $this->listing->list('alice', 2, null);
		$this->assertSame(['r1', 'r2'], array_column($first['conversations'], 'token'));
		$this->assertIsString($first['nextCursor']);

		$second = $this->listing->list('alice', 2, $first['nextCursor']);
		$this->assertSame(['r2b', 'r3'], array_column($second['conversations'], 'token'));
		$this->assertNull($second['nextCursor']);
	}

	public function testTheDefaultPageIsFiftyAndOutOfRangeLimitsAreRejected(): void {
		$rows = [];
		for ($i = 0; $i < 51; $i++) {
			$token = sprintf('t%02d', $i);
			$rows[] = [$this->room($token, 1000 - $i), $this->participant(1, 0)];
		}
		$this->givenRooms($rows);
		$this->resolver->method('keepsOutOfLobby')->willReturn(false);

		$page = $this->listing->list('alice', UnreadConversations::DEFAULT_PAGE, null);
		$this->assertCount(50, $page['conversations']);
		$this->assertIsString($page['nextCursor']);
		$rest = $this->listing->list('alice', 50, $page['nextCursor']);
		$this->assertSame(['t50'], array_column($rest['conversations'], 'token'));
		$this->assertNull($rest['nextCursor']);

		foreach ([0, 51] as $limit) {
			try {
				$this->listing->list('alice', $limit, null);
				$this->fail('limit ' . $limit . ' must be rejected');
			} catch (InvalidArgumentException $e) {
				$this->assertSame(Messages::invalidPageLimit(), $e->getMessage());
			}
		}
	}

	public function testAMalformedCursorIsRejected(): void {
		$this->services->expects($this->never())->method('manager');
		$cursors = ['@@@', '', str_repeat('a', 201), rtrim(strtr(base64_encode('{"a":-1,"t":"ab"}'), '+/', '-_'), '='), rtrim(strtr(base64_encode('{"a":1}'), '+/', '-_'), '=')];
		foreach ($cursors as $cursor) {
			try {
				$this->listing->list('alice', 50, $cursor);
				$this->fail('cursor must be rejected');
			} catch (InvalidArgumentException $e) {
				$this->assertSame(Messages::invalidCursor(), $e->getMessage());
			}
		}
	}

	public function testARoomTheActorCannotReadIsSkipped(): void {
		$open = $this->room('open', 5);
		$closed = $this->room('closed', 9);
		$this->givenRooms([
			[$closed, new RuntimeException('no participant')],
			[$open, $this->participant(3, 8)],
		]);
		$this->resolver->method('keepsOutOfLobby')->willReturn(false);

		$page = $this->listing->list('alice', 50, null);

		$this->assertSame(['open'], array_column($page['conversations'], 'token'));
		$this->assertFalse($closed->named);
		foreach ($this->participants->calls as $call) {
			$this->assertSame('alice', $call[0]);
			$this->assertFalse($call[1]);
		}
	}

	public function testTalkUnavailableStopsBeforeAnyParticipantLookup(): void {
		$this->services->method('manager')->willThrowException(new TalkUnavailableException());
		$this->services->expects($this->never())->method('participantService');

		$this->expectException(TalkUnavailableException::class);
		$this->listing->list('alice', 50, null);
	}

	public function testTheHandlerTurnsTalkUnavailableIntoThePublicErrorAndWritesNothing(): void {
		$this->services->method('manager')->willThrowException(new TalkUnavailableException());
		$handler = new ListUnreadHandler($this->listing, new TalkErrors($this->createMock(LoggerInterface::class)));

		$result = $handler->handle([], 'alice');

		$this->assertTrue($result['isError']);
		$this->assertSame(Messages::talkUnavailable(), $result['content'][0]['text']);
	}

	public function testTheReadGrantDeniedHidesTheToolBeforeAnyListing(): void {
		$registry = $this->registry(static fn (string $operation): bool => $operation !== 'read', true);
		$names = array_column($registry->list('alice'), 'name');
		$this->assertNotContains(TalkModule::TOOL_LIST_UNREAD, $names);

		$this->expectException(UnknownToolException::class);
		$registry->call(TalkModule::TOOL_LIST_UNREAD, [], 'alice');
	}

	public function testTalkDisabledHidesTheToolBeforeAnyListing(): void {
		$registry = $this->registry(static fn (string $operation): bool => true, false);
		$names = array_column($registry->list('alice'), 'name');
		$this->assertNotContains(TalkModule::TOOL_LIST_UNREAD, $names);

		$this->expectException(UnknownToolException::class);
		$registry->call(TalkModule::TOOL_LIST_UNREAD, [], 'alice');
	}

	/**
	 * @param list<array{0: UnreadRoomStub, 1: UnreadParticipantStub|Throwable}> $rows
	 */
	private function givenRooms(array $rows): void {
		$rooms = [];
		$byId = [];
		foreach ($rows as [$room, $participant]) {
			$rooms[] = $room;
			$byId[spl_object_id($room)] = $participant;
		}
		$this->participants = new UnreadParticipantServiceStub($byId);
		$this->services->method('manager')->willReturn(new UnreadManagerStub($rooms));
		$this->services->method('participantService')->willReturn($this->participants);
	}

	private function room(string $token, ?int $activity): UnreadRoomStub {
		return new UnreadRoomStub($token, $activity);
	}

	private function participant(int $unread, int $lastRead, bool $archived = false): UnreadParticipantStub {
		return new UnreadParticipantStub($unread, $lastRead, $archived);
	}

	/** @param callable(string): bool $grant whether that talk operation is allowed */
	private function registry(callable $grant, bool $talkEnabled): ToolRegistry {
		$policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new \OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore());
		foreach (GrantCatalog::CATALOG['talk'] as $operation) {
			$policy->setGrant('alice', 'talk', $operation, $grant($operation));
		}
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn($talkEnabled);
		$apps->method('getAppVersion')->willReturn('24.0.6');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$logger = $this->createMock(LoggerInterface::class);
		$module = TalkTestModule::build(
			$this->services,
			$this->createMock(\OCA\Mcp\Modules\Talk\Reading\ConversationReader::class),
			$this->resolver,
			$this->createMock(\OCA\Mcp\Modules\Talk\Writing\ConversationWriter::class),
			$this->createMock(\OCA\Mcp\Modules\Talk\Attachments\FileSharer::class),
			$this->createMock(\OCA\Mcp\Modules\Talk\Writing\WritePreview::class),
			$this->createMock(\OCA\Mcp\Modules\Talk\Locating\UserConversationResolver::class),
			$this->createMock(\OCA\Mcp\Modules\Talk\Groups\GroupCreator::class),
			$this->createMock(\OCA\Mcp\Modules\Talk\ReferenceLinker::class),
			$logger,
		);
		return new ToolRegistry([$module], $policy, $apps, $users, $logger);
	}
}

/** Room double. Naming is recorded so a skipped room can prove it was not described. */
final class UnreadRoomStub {
	public bool $named = false;

	public function __construct(
		private string $token,
		private ?int $lastActivity,
		private int $type = 2,
	) {
	}

	public function getToken(): string {
		return $this->token;
	}

	public function getType(): int {
		return $this->type;
	}

	public function getDisplayName(string $userId, bool $forceName = true): string {
		$this->named = true;
		return 'Name ' . $this->token;
	}

	public function getLastActivity(): ?DateTimeImmutable {
		return $this->lastActivity === null ? null : new DateTimeImmutable('@' . $this->lastActivity);
	}

	public function setLastReadMessage(int $message): void {
		throw new RuntimeException('setLastReadMessage');
	}
}

/** Participant double whose writing methods fail the test if the listing reaches them. */
final class UnreadParticipantStub {
	public function __construct(
		private int $unread,
		private int $lastRead,
		private bool $archived,
	) {
	}

	public function getAttendee(): object {
		return new class ($this->unread, $this->lastRead, $this->archived) {
			public function __construct(
				private int $unread,
				private int $lastRead,
				private bool $archived,
			) {
			}

			public function getUnreadMessages(): int {
				return $this->unread;
			}

			public function getLastReadMessage(): int {
				return $this->lastRead;
			}

			public function getParticipantType(): int {
				return 3;
			}

			public function isArchived(): bool {
				return $this->archived;
			}

			public function setLastReadMessage(int $message): void {
				throw new RuntimeException('setLastReadMessage');
			}

			public function setUnreadMessages(int $count): void {
				throw new RuntimeException('setUnreadMessages');
			}
		};
	}

	public function markAsRead(): void {
		throw new RuntimeException('markAsRead');
	}
}

/** Manager double that only lists rooms. */
final class UnreadManagerStub {
	/** @param list<UnreadRoomStub> $rooms */
	public function __construct(private array $rooms) {
	}

	/** @return list<UnreadRoomStub> */
	public function getRoomsForUser(string $userId): array {
		return $this->rooms;
	}
}

/** Participant lookup that records the session argument and can refuse a room. */
final class UnreadParticipantServiceStub {
	/** @var list<array{0: string, 1: mixed}> */
	public array $calls = [];

	/** @param array<int, UnreadParticipantStub|Throwable> $byId */
	public function __construct(private array $byId) {
	}

	public function getParticipant(object $room, string $userId, mixed $session = null): object {
		$this->calls[] = [$userId, $session];
		if ($session !== false) {
			throw new RuntimeException('a session must not be opened');
		}
		$participant = $this->byId[spl_object_id($room)] ?? null;
		if ($participant instanceof Throwable) {
			throw $participant;
		}
		if ($participant === null) {
			throw new RuntimeException('unexpected room');
		}
		return $participant;
	}
}
