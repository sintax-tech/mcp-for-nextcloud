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
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCA\Mcp\Mcp\Tools\Discovery\ToolAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Talk\Attachments\AttachmentAccess;
use OCA\Mcp\Modules\Talk\Attachments\AttachmentMessage;
use OCA\Mcp\Modules\Talk\Attachments\FileSharer;
use OCA\Mcp\Modules\Talk\Failures\TalkErrors;
use OCA\Mcp\Modules\Talk\Groups\CreateGroupHandler;
use OCA\Mcp\Modules\Talk\Groups\CreateGroupPreview;
use OCA\Mcp\Modules\Talk\Groups\GroupCreator;
use OCA\Mcp\Modules\Talk\Integration\TalkServices;
use OCA\Mcp\Modules\Talk\Integration\UnreadRoomView;
use OCA\Mcp\Modules\Talk\Locating\ConversationResolver;
use OCA\Mcp\Modules\Talk\Locating\UserConversationResolver;
use OCA\Mcp\Modules\Talk\Presentation\ActorNames;
use OCA\Mcp\Modules\Talk\ReferenceLinker;
use OCA\Mcp\Modules\Talk\Replying\ReplyDraft;
use OCA\Mcp\Modules\Talk\Replying\ReplyHandler;
use OCA\Mcp\Modules\Talk\Replying\ReplyPreview;
use OCA\Mcp\Modules\Talk\TalkArguments;
use OCA\Mcp\Modules\Talk\TalkMeetingSteps;
use OCA\Mcp\Modules\Talk\Writing\ConversationWriter;
use OCA\Mcp\Modules\Talk\Writing\WritePreview;
use OCA\Mcp\Platform\Meetings\MeetingStep;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\IComment;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The Talk steps of a meeting go through the very plans and effects of talk_create_group and talk_reply.
 *
 * Every Talk call that persists something is counted before it answers (MeetingTalkWrites), so a swallowed
 * exception cannot hide it. tearDown requires exactly the writes a test expects: none for a plan or a refusal, and
 * for a confirmed step the writes the original tool makes. The grant, Guests and app decision is the registry's own
 * ToolAccess, asked for the original tool.
 */
final class TalkMeetingStepsTest extends TestCase {
	/** @var list<string> writes the current test expects, in order */
	private array $expectedWrites = [];
	/** @var array<string, bool> operation of the talk module to whether the actor holds it */
	private array $grants = ['create' => true, 'reply' => true];
	/** Whether Talk is enabled for the actor. */
	private bool $appEnabled = true;
	/** @var list<array{string, string, list<string>}> grant questions asked, in order */
	private array $asked = [];
	/** Whether bob may be enumerated by alice. */
	private bool $bobReachable = true;
	private MeetingTalkRoom $group;
	private MeetingTalkRooms $rooms;
	private MeetingTalkParticipants $participants;
	private MeetingTalkChat $chat;
	private TalkMeetingSteps $steps;

	protected function setUp(): void {
		parent::setUp();
		MeetingTalkWrites::$calls = [];
		$this->group = new MeetingTalkRoom('grupo1234', 'Projeto', 2, 2, new DateTimeImmutable('-1 minute'));
		$manager = new MeetingTalkManager(['grupo1234' => $this->group, 'leitura12' => new MeetingTalkRoom('leitura12', 'Avisos', 2, 0, null, 1)]);
		$this->rooms = new MeetingTalkRooms($manager);
		$this->participants = new MeetingTalkParticipants();
		$this->chat = new MeetingTalkChat($this->comment());

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => in_array($uid, ['alice', 'bob', 'carla'], true) ? $this->user($uid) : null);
		$users->method('getDisplayName')->willReturnCallback(static fn (string $uid): ?string => ['bob' => 'Bob Souza', 'carla' => 'Carla Dias'][$uid] ?? null);
		$shares = $this->createMock(IShareManager::class);
		$shares->method('currentUserCanEnumerateTargetUser')->willReturnCallback(fn (IUser $actor, IUser $target): bool => $target->getUID() !== 'bob' || $this->bobReachable);

		$talk = $this->createMock(TalkServices::class);
		$talk->method('isEnabledFor')->willReturn(true);
		$talk->method('manager')->willReturn($manager);
		$talk->method('participantService')->willReturn($this->participants);
		$talk->method('roomService')->willReturn($this->rooms);
		$talk->method('chatManager')->willReturn($this->chat);
		$talk->method('conversationConstants')->willReturn([
			'chatPermission' => 128, 'lobbyIgnorePermission' => 8, 'readOnly' => 1, 'changelogType' => 4,
			'lobbyNone' => 0, 'actorUsers' => 'users', 'groupType' => 2,
		]);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): \DateTime => new \DateTime('2026-01-02 03:04:05'));
		$logger = new NullLogger();
		$views = new UnreadRoomView($users, 1);
		$resolver = new ConversationResolver($talk, $views);
		$attachments = $this->createMock(AttachmentAccess::class);
		$writer = new ConversationWriter($talk, $time, new AttachmentMessage(), $attachments);
		$userConversations = new UserConversationResolver($talk, $users, $shares, $views);
		$preview = new WritePreview($writer, $this->createMock(FileSharer::class), $attachments, new ActorNames($users));
		$arguments = new TalkArguments();
		$errors = new TalkErrors($logger);
		$draft = new ReplyDraft($arguments, $resolver, $this->createMock(ReferenceLinker::class));

		$policy = $this->createMock(GrantPolicy::class);
		$policy->method('grantedAny')->willReturnCallback(function (string $uid, string $module, array $operations): bool {
			$this->asked[] = [$uid, $module, $operations];
			return $module === 'talk' && ($this->grants[$operations[0]] ?? false);
		});
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(fn (string $app): bool => $app === 'spreed' && $this->appEnabled);
		$apps->method('getAppVersion')->willReturn('25.0.6');
		$access = new ToolAccess($policy, $apps, $users, null, new CapabilityRegistry([], $logger));
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(static fn (string $route, array $parameters): string => 'https://cloud.example/' . $route . '/' . $parameters['token']);

		$this->steps = new TalkMeetingSteps(
			$access,
			new CreateGroupPreview($arguments, $userConversations, $preview, $errors),
			new CreateGroupHandler($arguments, new GroupCreator($talk, $users, $userConversations, $logger), $errors),
			new ReplyPreview($draft, $preview, $errors),
			new ReplyHandler($draft, $writer, $resolver, $errors),
			$urls,
		);
	}

	protected function tearDown(): void {
		$this->assertSame($this->expectedWrites, MeetingTalkWrites::$calls);
		parent::tearDown();
	}

	public function testTheRoomPlanIsTheOneOfTalkCreateGroupAndWritesNothing(): void {
		$plan = $this->steps->planRoom('alice', ' Reunião ', ['bob', 'carla']);

		$this->assertSame('talk_create_group', $plan['action']);
		$this->assertSame('Reunião', $plan['draft']['name']);
		$this->assertSame(['bob', 'carla'], array_column($plan['draft']['participants'], 'id'));
		$this->assertSame([['alice', 'talk', ['create']]], $this->asked);
	}

	public function testTheConfirmedRoomIsCreatedOnceWithItsInvitationsAndItsInstanceUrl(): void {
		$this->expectedWrites = ['RoomService::createConversation', 'ParticipantService::addUsers:bob', 'ParticipantService::addUsers:carla'];

		$step = $this->steps->createRoom('alice', 'Reunião', ['bob', 'carla']);

		$this->assertSame(MeetingStep::APPLIED, $step->status);
		$this->assertSame(['conversation_token' => 'novasala1'], $step->ids);
		$this->assertSame('https://cloud.example/spreed.Page.showCall/novasala1', $step->result['url']);
		$this->assertSame([2, 'Reunião', 'alice'], $this->rooms->created);
	}

	public function testAFailedInvitationLeavesThePartialRoomInPlace(): void {
		$this->participants->refuse = 'carla';
		$this->expectedWrites = ['RoomService::createConversation', 'ParticipantService::addUsers:bob', 'ParticipantService::addUsers:carla'];

		$step = $this->steps->createRoom('alice', 'Reunião', ['bob', 'carla']);

		$this->assertSame(MeetingStep::PARTIAL, $step->status);
		$this->assertSame('novasala1', $step->ids['conversation_token']);
		$this->assertSame(['carla'], array_column($step->result['invitations_failed'], 'id'));
	}

	public function testAnUnreachableGuestRefusesTheRoomBeforeItExists(): void {
		$this->bobReachable = false;

		$this->assertSame(MeetingStep::FAILED, $this->steps->createRoom('alice', 'Reunião', ['bob'])->status);
		$this->expectException(ToolFailure::class);
		$this->steps->planRoom('alice', 'Reunião', ['bob']);
	}

	/**
	 * @return array<string, array{string, \Closure(self): void}>
	 */
	public static function deniedSteps(): array {
		return [
			'room without talk.create' => ['room', static function (self $test): void { $test->grants['create'] = false; }],
			'agenda without talk.reply' => ['agenda', static function (self $test): void { $test->grants['reply'] = false; }],
			'room with Talk off for the actor' => ['room', static function (self $test): void { $test->appEnabled = false; }],
			'agenda with Talk off for the actor' => ['agenda', static function (self $test): void { $test->appEnabled = false; }],
		];
	}

	/** The registry gate of the original tool refuses the plan and the step, before any Talk call. */
	#[DataProvider('deniedSteps')]
	public function testTheRegistryGateOfTheOriginalToolRefusesPlanAndStep(string $step, \Closure $deny): void {
		$deny($this);

		$result = $step === 'room' ? $this->steps->createRoom('alice', 'Reunião', ['bob']) : $this->steps->postAgenda('alice', 'grupo1234', 'Pauta');
		$this->assertSame(MeetingStep::FAILED, $result->status);
		$this->assertStringContainsString($step === 'room' ? 'talk_create_group' : 'talk_reply', (string)$result->message);
		$this->expectException(ToolFailure::class);
		$step === 'room' ? $this->steps->planRoom('alice', 'Reunião', ['bob']) : $this->steps->planAgenda('alice', 'Pauta');
	}

	public function testTheAgendaPlanIsTheTextTalkReplyWouldStoreAndWritesNothing(): void {
		$plan = $this->steps->planAgenda('alice', "  1. Abertura\n2. Prazos  ");

		$this->assertSame(['action' => 'talk_reply', 'conversation' => null, 'draft' => ['message' => "1. Abertura\n2. Prazos"]], $plan);
		$this->assertSame([['alice', 'talk', ['reply']]], $this->asked);
	}

	public function testAnAgendaTalkReplyRefusesIsRefusedInThePlan(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->steps->planAgenda('alice', str_repeat('a', ConversationWriter::MAX_MESSAGE_LENGTH + 1));
	}

	public function testTheConfirmedAgendaOpensAnElapsedLobbyAndSendsOnce(): void {
		$this->expectedWrites = ['RoomService::validateLobbyTimer', 'ParticipantService::ensureOneToOneRoomIsFilled', 'ChatManager::sendMessage'];

		$step = $this->steps->postAgenda('alice', 'grupo1234', 'Pauta');

		$this->assertSame(MeetingStep::APPLIED, $step->status);
		$this->assertSame(['conversation_token' => 'grupo1234', 'messageId' => 42], $step->ids);
		$this->assertSame(['Pauta'], $this->chat->sent);
	}

	public function testAReadOnlyRoomIsRefusedBeforeAnyTalkWrite(): void {
		$step = $this->steps->postAgenda('alice', 'leitura12', 'Pauta');

		$this->assertSame(MeetingStep::FAILED, $step->status);
		$this->assertSame([], $this->chat->sent);
	}

	public function testAFailureOfTheSendItselfIsUncertainAndNeverRetried(): void {
		$this->chat->fails = true;
		$this->expectedWrites = ['RoomService::validateLobbyTimer', 'ParticipantService::ensureOneToOneRoomIsFilled', 'ChatManager::sendMessage'];

		$step = $this->steps->postAgenda('alice', 'grupo1234', 'Pauta');

		$this->assertSame(MeetingStep::UNCERTAIN, $step->status);
		$this->assertSame(['conversation_token' => 'grupo1234'], $step->ids);
	}

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	private function comment(): IComment {
		$comment = $this->createMock(IComment::class);
		$comment->method('getId')->willReturn('42');
		return $comment;
	}
}

/** Counts every Talk call that would persist something, in the order it was made. */
final class MeetingTalkWrites {
	/** @var list<string> */
	public static array $calls = [];
}

/** OCA\Talk\Room: getters read loaded values. */
final class MeetingTalkRoom {
	public function __construct(
		private string $token,
		private string $name,
		private int $type,
		public int $lobby = 0,
		private ?DateTimeInterface $timer = null,
		private int $readOnly = 0,
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
		return $this->readOnly;
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
}

/** OCA\Talk\Participant of alice, with the chat permission and no lobby bypass. */
final class MeetingTalkParticipant {
	public function getPermissions(): int {
		return 128;
	}
}

/** OCA\Talk\Manager: the room lookup, a read. */
final class MeetingTalkManager {
	/** @param array<string, MeetingTalkRoom> $rooms */
	public function __construct(public array $rooms) {}

	public function getRoomForUserByToken(string $token, ?string $userId, ?string $sessionId = null): object {
		return $this->rooms[$token] ?? throw new RuntimeException('Room not found');
	}
}

/** OCA\Talk\Service\ParticipantService: reads, and the invitations and pair fills counted. */
final class MeetingTalkParticipants {
	/** Account whose invitation Talk refuses, if any. */
	public ?string $refuse = null;

	public function getParticipant(object $room, ?string $userId, mixed $sessionId = null): object {
		return new MeetingTalkParticipant();
	}

	/** @param list<array{actorType:string, actorId:string, displayName:string}> $participants */
	public function addUsers(object $room, array $participants, ?IUser $addedBy = null): void {
		$id = $participants[0]['actorId'];
		MeetingTalkWrites::$calls[] = 'ParticipantService::addUsers:' . $id;
		if ($id === $this->refuse) {
			throw new RuntimeException('invitation refused');
		}
	}

	public function ensureOneToOneRoomIsFilled(object $room, ?string $enforceUserId = null): void {
		MeetingTalkWrites::$calls[] = 'ParticipantService::ensureOneToOneRoomIsFilled';
	}
}

/** OCA\Talk\Service\RoomService: every method here persists something. */
final class MeetingTalkRooms {
	/** @var list<int|string> type, name and owner of the created room */
	public array $created = [];

	public function __construct(private MeetingTalkManager $manager) {}

	public function createConversation(int $type, string $name, IUser $owner): object {
		MeetingTalkWrites::$calls[] = 'RoomService::createConversation';
		$this->created = [$type, $name, $owner->getUID()];
		return $this->manager->rooms['novasala1'] = new MeetingTalkRoom('novasala1', $name, $type);
	}

	public function validateLobbyTimer(object $room): void {
		MeetingTalkWrites::$calls[] = 'RoomService::validateLobbyTimer';
		$room->lobby = 0;
	}
}

/** OCA\Talk\Chat\ChatManager: messages are counted. */
final class MeetingTalkChat {
	/** @var list<string> bodies Talk stored */
	public array $sent = [];
	/** Whether the send fails after it was attempted. */
	public bool $fails = false;

	public function __construct(private IComment $comment) {}

	public function sendMessage(object $room, ?object $participant, string $actorType, string $actorId, string $message, mixed ...$rest): IComment {
		MeetingTalkWrites::$calls[] = 'ChatManager::sendMessage';
		if ($this->fails) {
			throw new RuntimeException('listener failed after the insert');
		}
		$this->sent[] = $message;
		return $this->comment;
	}
}
