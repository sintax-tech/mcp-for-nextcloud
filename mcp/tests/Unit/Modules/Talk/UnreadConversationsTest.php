<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Talk;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Mcp\Access\Grants\GrantCatalog;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Mcp\Tools\UnknownToolException;
use OCA\Mcp\Modules\Talk\Failures\TalkErrors;
use OCA\Mcp\Modules\Talk\Failures\TalkUnavailableException;
use OCA\Mcp\Modules\Talk\Integration\TalkServices;
use OCA\Mcp\Modules\Talk\Integration\UnreadCounter;
use OCA\Mcp\Modules\Talk\Integration\UnreadRoomView;
use OCA\Mcp\Modules\Talk\Presentation\Messages;
use OCA\Mcp\Modules\Talk\Presentation\PageMessages;
use OCA\Mcp\Modules\Talk\Presentation\RoomNames;
use OCA\Mcp\Modules\Talk\Reading\ListUnreadHandler;
use OCA\Mcp\Modules\Talk\Reading\UnreadConversations;
use OCA\Mcp\Modules\Talk\Reading\UnreadCursor;
use OCA\Mcp\Modules\Talk\TalkModule;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\Tools\Talk\TalkTestModule;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The unread listing against the real lobby and name view and the real unread counter.
 *
 * This process cannot load OCA\Talk, so a double stands in for each room and for the chat manager. The view and the
 * counter are the production classes. A local room keeps the attendee column at 0, as Talk does, so its count only
 * comes from the chat. A call that would persist a lobby, a name or a read marker throws. The cell that loads Talk is
 * the proof that nothing is written.
 */
final class UnreadConversationsTest extends TestCase {
	private TalkServices&MockObject $services;
	private UnreadConversations $listing;
	private UnreadCursor $cursor;
	private UnreadParticipantServiceStub $participants;
	private UnreadChatManagerStub $chat;

	protected function setUp(): void {
		parent::setUp();
		UnreadWriteSpy::$calls = 0;
		$this->services = $this->createMock(TalkServices::class);
		$this->services->expects($this->never())->method('roomService');
		$config = new InMemoryConfig();
		$config->system['secret'] = 'talk-unread-secret';
		$this->cursor = new UnreadCursor(new PlanState($config->mock($this)));
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => $uid === 'alice' ? $this->createMock(IUser::class) : null);
		$users->method('getDisplayName')->willReturnCallback(static function (string $uid): ?string {
			if ($uid === 'carol') {
				return 'Carol';
			}
			return $uid === 'noname' ? null : $uid;
		});
		$this->listing = new UnreadConversations($this->services, new UnreadRoomView($users, 1), new UnreadCounter($users), $this->cursor);
	}

	protected function tearDown(): void {
		$this->assertSame(0, UnreadWriteSpy::$calls);
		parent::tearDown();
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

		$page = $this->listing->list('alice', 50, null);

		$this->assertNull($page['nextCursor']);
		$this->assertSame(['open', 'kept'], array_column($page['conversations'], 'token'));
		$this->assertSame(4, $page['conversations'][0]['unreadMessages']);
		$this->assertSame(12, $page['conversations'][0]['lastReadMessage']);
		$this->assertSame('Name open', $page['conversations'][0]['displayName']);
		$this->assertFalse($page['conversations'][0]['archived']);
		$this->assertSame(300, $page['conversations'][0]['lastActivity']);
		$this->assertTrue($page['conversations'][1]['archived']);
		// A marker of 0 is reported as Talk reports it: from the first message.
		$this->assertSame(-2, $page['conversations'][1]['lastReadMessage']);
		$this->assertFalse($quiet->described);
	}

	public function testALocalRoomIsCountedFromTheChatWhileTheAttendeeColumnStaysZero(): void {
		$room = $this->room('local', 50);
		$participant = $this->participant(3, 40);
		$this->givenRooms([[$room, $participant]]);

		$page = $this->listing->list('alice', 50, null);

		$this->assertSame(0, $participant->column);
		$this->assertSame(['local'], array_column($page['conversations'], 'token'));
		$this->assertSame(3, $page['conversations'][0]['unreadMessages']);
		$this->assertSame(40, $page['conversations'][0]['lastReadMessage']);
		$this->assertSame([['local', 40]], $this->chat->counted);
	}

	public function testReadingUpToTheLastMessageIsZeroWithoutCounting(): void {
		$room = $this->room('caught-up', 50);
		$room->lastMessageId = 40;
		$this->givenRooms([[$room, $this->participant(5, 40)]]);

		$page = $this->listing->list('alice', 50, null);

		$this->assertSame([], $page['conversations']);
		$this->assertSame([], $this->chat->counted);
	}

	public function testTheFirstMessageMarkerWithNothingCountedShowsOneUnread(): void {
		$room = $this->room('marked', 50);
		$this->givenRooms([[$room, $this->participant(0, -2)]]);

		$page = $this->listing->list('alice', 50, null);

		$this->assertSame(1, $page['conversations'][0]['unreadMessages']);
		$this->assertSame(-2, $page['conversations'][0]['lastReadMessage']);
	}

	public function testALegacyMarkerIsConvertedInMemoryAndNeverSaved(): void {
		$room = $this->room('legacy', 50);
		$room->legacyRead = 7;
		$participant = $this->participant(2, -1);
		$this->givenRooms([[$room, $participant]]);

		$page = $this->listing->list('alice', 50, null);

		$this->assertSame(2, $page['conversations'][0]['unreadMessages']);
		$this->assertSame(7, $page['conversations'][0]['lastReadMessage']);
		$this->assertSame([['legacy', 7]], $this->chat->counted);
		$this->assertSame(['legacy'], $this->chat->converted);
		$this->assertSame(-1, $participant->lastRead);
	}

	public function testALegacyMarkerWithoutAReadableAccountOmitsTheRoom(): void {
		$room = $this->room('legacy', 50);
		$room->legacyRead = 7;
		$this->givenRooms([[$room, $this->participant(2, -1)]]);

		$page = $this->listing->list('ghost', 50, null);

		$this->assertSame([], $page['conversations']);
		$this->assertSame([], $this->chat->converted);
		$this->assertSame([], $this->chat->counted);
	}

	public function testAFederatedRoomKeepsTheCounterTalkStoredOnTheAttendee(): void {
		$room = $this->room('remote', 50);
		$room->federated = true;
		$participant = $this->participant(0, 12);
		$participant->column = 4;
		$this->givenRooms([[$room, $participant]]);

		$page = $this->listing->list('alice', 50, null);

		$this->assertSame(4, $page['conversations'][0]['unreadMessages']);
		$this->assertSame(12, $page['conversations'][0]['lastReadMessage']);
		$this->assertSame([], $this->chat->counted);
	}

	public function testReleaseNotesAndPasswordRequestsGetTheNamesTalkGivesThem(): void {
		$changelog = $this->room('changelog', 20, 4, 'alice');
		$password = $this->room('password', 10, 3, 'report.pdf');
		$password->objectType = 'share:password';
		$this->givenRooms([
			[$changelog, $this->participant(1, 0)],
			[$password, $this->participant(1, 0)],
		]);

		$names = array_column($this->listing->list('alice', 50, null)['conversations'], 'displayName', 'token');

		$this->assertSame(RoomNames::changelog(), $names['changelog']);
		$this->assertSame('Talk updates', $names['changelog']);
		$this->assertSame(RoomNames::passwordRequest('report.pdf'), $names['password']);
		$this->assertSame('Password request: report.pdf', $names['password']);
		$this->assertFalse($changelog->described);
		$this->assertFalse($password->described);
		$this->assertSame(0, $this->participants->userIdReads);
	}

	public function testAnEmptyUnreadSetReturnsNoConversations(): void {
		$quiet = $this->room('quiet', 10);
		$this->givenRooms([[$quiet, $this->participant(0, 9)]]);

		$page = $this->listing->list('alice', 50, null);

		$this->assertSame([], $page['conversations']);
		$this->assertNull($page['nextCursor']);
		$this->assertFalse($quiet->described);
	}

	public function testALobbyRoomIsOmittedAndItsCounterIsAbsent(): void {
		$open = $this->room('open', 20);
		$barred = $this->room('barred', 90);
		$barred->lobbyState = 1;
		$barred->lobbyTimer = time() + 3600;
		$this->givenRooms([
			[$open, $this->participant(1, 1)],
			[$barred, $this->participant(91, 4)],
		]);

		$encoded = json_encode($this->listing->list('alice', 50, null), JSON_THROW_ON_ERROR);

		$this->assertStringNotContainsString('barred', $encoded);
		$this->assertStringNotContainsString('91', $encoded);
		$this->assertStringContainsString('open', $encoded);
		$this->assertFalse($barred->described);
	}

	public function testAnElapsedLobbyIsOpenInMemoryAndANullTimerStaysBarred(): void {
		$elapsed = $this->room('elapsed', 40);
		$elapsed->lobbyState = 1;
		$elapsed->lobbyTimer = time() - 10;
		$waiting = $this->room('waiting', 30);
		$waiting->lobbyState = 1;
		$unreadable = $this->room('unreadable', 20);
		$unreadable->lobbyState = null;
		$this->givenRooms([
			[$elapsed, $this->participant(2, 1)],
			[$waiting, $this->participant(3, 1)],
			[$unreadable, $this->participant(4, 1)],
		]);

		$page = $this->listing->list('alice', 50, null);

		$this->assertSame(['elapsed'], array_column($page['conversations'], 'token'));
		$this->assertFalse($elapsed->described);
	}

	public function testLobbyIgnoreLetsTheParticipantThrough(): void {
		$room = $this->room('passed', 15);
		$room->lobbyState = 1;
		$room->lobbyTimer = time() + 3600;
		$participant = $this->participant(5, 1);
		$participant->permissions = 8;
		$this->givenRooms([[$room, $participant]]);

		$page = $this->listing->list('alice', 50, null);

		$this->assertSame(['passed'], array_column($page['conversations'], 'token'));
	}

	public function testAnUnnamedRoomContributesAnEmptyDisplayName(): void {
		$room = $this->room('blank', 12, storedName: '');
		$this->givenRooms([[$room, $this->participant(1, 0)]]);

		$page = $this->listing->list('alice', 50, null);

		$this->assertSame('', $page['conversations'][0]['displayName']);
		$this->assertSame('blank', $page['conversations'][0]['token']);
		$this->assertFalse($room->described);
		$this->assertSame(0, $this->participants->userIdReads);
	}

	public function testADirectConversationUsesTheStoredAccountIds(): void {
		$named = $this->room('direct', 11, 1, '["alice","carol"]');
		$fallback = $this->room('plain', 10, 1, '["alice","noname"]');
		$legacy = $this->room('legacy', 9, 1, '');
		$other = $this->room('other', 8, 1, '["bob","carol"]');
		$this->givenRooms([
			[$named, $this->participant(1, 0)],
			[$fallback, $this->participant(1, 0)],
			[$legacy, $this->participant(1, 0)],
			[$other, $this->participant(1, 0)],
		]);

		$page = $this->listing->list('alice', 50, null);
		$names = array_column($page['conversations'], 'displayName', 'token');

		$this->assertSame('Carol', $names['direct']);
		$this->assertSame('noname', $names['plain']);
		$this->assertSame('', $names['legacy']);
		$this->assertSame('', $names['other']);
		$this->assertFalse($named->described);
		$this->assertFalse($legacy->described);
		// Only the empty direct name is not a stored pair, so only that room reads the account ids.
		$this->assertSame(1, $this->participants->userIdReads);
	}

	/** The same direct-name rules as the conversation list: legacy, empty, a removed person, and the reader left alone. */
	public function testUnreadDirectNamesAreRebuiltWithoutWriting(): void {
		$legacy = $this->room('legacy', 40, 1, 'bob');
		$empty = $this->room('empty', 30, 1, '');
		$removed = $this->room('removed', 20, 1, 'bob');
		$alone = $this->room('alone', 10, 1, 'alice');
		$legacyParticipant = $this->participant(1, 0);
		$emptyParticipant = $this->participant(1, 0);
		$removedParticipant = $this->participant(1, 0);
		$aloneParticipant = $this->participant(1, 0);
		$this->givenRooms([
			[$legacy, $legacyParticipant],
			[$empty, $emptyParticipant],
			[$removed, $removedParticipant],
			[$alone, $aloneParticipant],
		]);
		$this->participants->userIds = [
			spl_object_id($legacy) => ['alice', 'bob'],
			spl_object_id($empty) => ['bob', 'alice'],
			spl_object_id($removed) => ['alice'],
			spl_object_id($alone) => ['alice'],
		];

		$page = $this->listing->list('alice', 50, null);
		$names = array_column($page['conversations'], 'displayName', 'token');

		$this->assertSame('bob', $names['legacy']);
		$this->assertSame('bob', $names['empty']);
		$this->assertSame('bob', $names['removed']);
		$this->assertSame('', $names['alone']);
		$this->assertSame(4, $this->participants->userIdReads);
		$this->assertFalse($legacy->described);
		$this->assertSame($legacyParticipant->getAttendee(), $legacyParticipant->getAttendee());
	}

	/** A participant list that cannot be read leaves the unread direct name empty. */
	public function testAnUnreadableParticipantListLeavesTheUnreadNameEmpty(): void {
		$room = $this->room('broken', 20, 1, 'bob');
		$this->givenRooms([[$room, $this->participant(1, 0)]]);
		$this->participants->failUserIds = true;

		$page = $this->listing->list('alice', 50, null);

		$this->assertSame('', $page['conversations'][0]['displayName']);
		$this->assertSame(1, $this->participants->userIdReads);
		$this->assertFalse($room->described);
	}

	public function testPagesStopAtFiftyAndTheCursorContinuesAfterTheLastRow(): void {
		$rows = [];
		for ($i = 1; $i <= 3; $i++) {
			$rows[] = [$this->room('r' . $i, 300 - $i), $this->participant($i, 10 + $i)];
		}
		$rows[] = [$this->room('r2b', 298), $this->participant(6, 1)];
		$this->givenRooms($rows);

		$first = $this->listing->list('alice', 2, null);
		$this->assertSame(['r1', 'r2'], array_column($first['conversations'], 'token'));
		$this->assertIsString($first['nextCursor']);

		$second = $this->listing->list('alice', 2, $first['nextCursor']);
		$this->assertSame(['r2b', 'r3'], array_column($second['conversations'], 'token'));
		$this->assertNull($second['nextCursor']);
	}

	public function testANewerMessageBetweenPagesLeavesTheContinuation(): void {
		$newest = $this->room('newest', 300);
		$moved = $this->room('moved', 200);
		$older = $this->room('older', 100);
		$this->givenRooms([
			[$newest, $this->participant(1, 0)],
			[$moved, $this->participant(1, 0)],
			[$older, $this->participant(1, 0)],
		]);

		$first = $this->listing->list('alice', 1, null);
		$this->assertSame(['newest'], array_column($first['conversations'], 'token'));
		$moved->lastActivity = 400;

		$second = $this->listing->list('alice', 1, $first['nextCursor']);
		$this->assertSame(['older'], array_column($second['conversations'], 'token'));

		$again = $this->listing->list('alice', 50, null);
		$this->assertSame(['moved', 'newest', 'older'], array_column($again['conversations'], 'token'));
	}

	public function testTheDefaultPageIsFiftyAndOutOfRangeLimitsAreRejected(): void {
		$rows = [];
		for ($i = 0; $i < 51; $i++) {
			$token = sprintf('t%02d', $i);
			$rows[] = [$this->room($token, 1000 - $i), $this->participant(1, 0)];
		}
		$this->givenRooms($rows);

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
				$this->assertSame(PageMessages::invalidPageLimit(), $e->getMessage());
			}
		}
	}

	public function testAMalformedCursorIsRejectedBeforeTalkIsTouched(): void {
		$this->services->expects($this->never())->method('manager');
		$swapped = rtrim(strtr(base64_encode('{"t":"ab","a":1}'), '+/', '-_'), '=');
		$signed = $this->cursor->next(1, 'ab', TalkModule::TOOL_LIST_UNREAD, 'alice');
		$parts = explode('.', $signed);
		$cursors = [
			'@@@',
			'',
			str_repeat('a', 513),
			rtrim(strtr(base64_encode('{"a":-1,"t":"ab"}'), '+/', '-_'), '='),
			$this->cursor->next(1, 'ab', TalkModule::TOOL_LIST_UNREAD, 'bob'),
			$parts[0] . '.' . $swapped . '.' . $parts[2],
			substr($signed, 0, -1) . ($signed[-1] === 'a' ? 'b' : 'a'),
		];
		foreach ($cursors as $cursor) {
			try {
				$this->listing->list('alice', 50, $cursor);
				$this->fail('cursor must be rejected');
			} catch (InvalidArgumentException $e) {
				$this->assertSame(PageMessages::invalidCursor(), $e->getMessage());
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

		$page = $this->listing->list('alice', 50, null);

		$this->assertSame(['open'], array_column($page['conversations'], 'token'));
		$this->assertFalse($closed->described);
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

	public function testIncompleteLobbyConstantsAreNotAnEmptyInbox(): void {
		$this->services->method('manager')->willReturn(new UnreadManagerStub([]));
		$this->services->method('participantService')->willReturn(new \stdClass());
		$this->services->method('conversationConstants')->willReturn([]);

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
		$this->chat = new UnreadChatManagerStub($byId);
		$this->services->method('manager')->willReturn(new UnreadManagerStub($rooms));
		$this->services->method('participantService')->willReturn($this->participants);
		$this->services->method('chatManager')->willReturn($this->chat);
		$this->services->method('unreadMarkers')->willReturn(['unreadMigration' => -1, 'unreadFirstMessage' => -2]);
		$this->services->method('conversationConstants')->willReturn([
			'lobbyNone' => 0,
			'lobbyIgnorePermission' => 8,
			'changelogType' => 4,
		]);
	}

	private function room(string $token, ?int $activity, int $type = 2, ?string $storedName = null): UnreadRoomStub {
		return new UnreadRoomStub($token, $activity, $type, $storedName);
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
			$this->createMock(\OCA\Mcp\Modules\Talk\Locating\ConversationResolver::class),
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

/** Room double. Describing it through the writing name methods fails the test. */
final class UnreadRoomStub {
	public bool $described = false;
	public int|null $lobbyState = 0;
	public ?int $lobbyTimer = null;
	public bool $federated = false;
	public int $lastMessageId = 1000;
	public string $objectType = '';
	public int $legacyRead = 0;
	private string $name;

	public function __construct(
		private string $token,
		public ?int $lastActivity,
		private int $type = 2,
		?string $storedName = null,
	) {
		$this->name = $storedName ?? ('Name ' . $token);
	}

	public function getToken(): string {
		return $this->token;
	}

	public function getType(): int {
		return $this->type;
	}

	public function getObjectType(): string {
		return $this->objectType;
	}

	public function isFederatedConversation(): bool {
		return $this->federated;
	}

	public function getLastMessageId(): int {
		return $this->lastMessageId;
	}

	public function getLobbyState(): int|null {
		return $this->lobbyState;
	}

	public function getLobbyTimer(): ?DateTimeImmutable {
		return $this->lobbyTimer === null ? null : new DateTimeImmutable('@' . $this->lobbyTimer);
	}

	public function getDisplayName(string $userId, bool $forceName = true): string {
		$this->described = true;
		UnreadWriteSpy::hit();
		throw new RuntimeException('getDisplayName');
	}

	public function getName(): string {
		$this->described = true;
		UnreadWriteSpy::hit();
		throw new RuntimeException('getName');
	}

	public function getLastActivity(): ?DateTimeImmutable {
		return $this->lastActivity === null ? null : new DateTimeImmutable('@' . $this->lastActivity);
	}

	public function setName(string $name): void {
		UnreadWriteSpy::hit();
		throw new RuntimeException('setName');
	}

	public function setLobby(int $state, ?DateTimeImmutable $timer = null): void {
		UnreadWriteSpy::hit();
		throw new RuntimeException('setLobby');
	}
}

/**
 * Participant double whose writing methods fail the test if the listing reaches them.
 *
 * $unread is what the chat holds after the marker; $column is the attendee counter, 0 unless the room is federated.
 */
final class UnreadParticipantStub {
	public int $permissions = 0;
	public int $column = 0;
	private object $attendee;

	public function __construct(
		public int $unread,
		public int $lastRead,
		private bool $archived,
	) {
		$this->attendee = new class ($this) {
			public function __construct(private UnreadParticipantStub $participant) {
			}

			public function getUnreadMessages(): int {
				return $this->participant->column;
			}

			public function getLastReadMessage(): int {
				return $this->participant->lastRead;
			}

			public function getParticipantType(): int {
				return 3;
			}

			public function isArchived(): bool {
				return $this->participant->archived();
			}

			public function setLastReadMessage(int $message): void {
				UnreadWriteSpy::hit();
				throw new RuntimeException('setLastReadMessage');
			}

			public function setUnreadMessages(int $count): void {
				UnreadWriteSpy::hit();
				throw new RuntimeException('setUnreadMessages');
			}
		};
	}

	public function getPermissions(): int {
		return $this->permissions;
	}

	public function archived(): bool {
		return $this->archived;
	}

	public function getAttendee(): object {
		return $this->attendee;
	}

	public function markAsRead(): void {
		UnreadWriteSpy::hit();
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

	public function resolveRoomDisplayName(object $room, string $userId): string {
		UnreadWriteSpy::hit();
		throw new RuntimeException('resolveRoomDisplayName');
	}
}

/** Participant lookup that records the session argument and can refuse a room. */
final class UnreadParticipantServiceStub {
	/** @var list<array{0: string, 1: mixed}> */
	public array $calls = [];
	public bool $failUserIds = false;
	public int $userIdReads = 0;

	/** @var array<int, list<string>> */
	public array $userIds = [];

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

	/** @return list<string> */
	public function getParticipantUserIds(object $room): array {
		$this->userIdReads++;
		if ($this->failUserIds) {
			throw new RuntimeException('getParticipantUserIds');
		}
		return $this->userIds[spl_object_id($room)] ?? [];
	}

	public function updateLastReadMessage(object $participant, int $lastReadMessage): void {
		UnreadWriteSpy::hit();
		throw new RuntimeException('updateLastReadMessage');
	}
}

/** Chat manager double: counts from the participant double and records every read; marking as read fails. */
final class UnreadChatManagerStub {
	/** @var list<array{0: string, 1: int}> token and marker of each count */
	public array $counted = [];
	/** @var list<string> tokens whose legacy marker was read */
	public array $converted = [];

	/** @param array<int, UnreadParticipantStub|Throwable> $byId */
	public function __construct(private array $byId) {
	}

	public function getUnreadCount(UnreadRoomStub $chat, int $lastReadMessage): int {
		if ($chat->federated) {
			throw new RuntimeException('a federated room is not counted locally');
		}
		$this->counted[] = [$chat->getToken(), $lastReadMessage];
		$participant = $this->byId[spl_object_id($chat)];
		return $participant instanceof UnreadParticipantStub ? $participant->unread : 0;
	}

	public function getLastReadMessageFromLegacy(UnreadRoomStub $chat, IUser $user): int {
		$this->converted[] = $chat->getToken();
		return $chat->legacyRead;
	}

	public function markAsRead(): void {
		UnreadWriteSpy::hit();
		throw new RuntimeException('markAsRead');
	}
}

/** Counts a writing call even when the caller catches the failure that follows. */
final class UnreadWriteSpy {
	public static int $calls = 0;

	public static function hit(): void {
		self::$calls++;
	}
}
