<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use OCA\Mcp\Modules\Calendar\Locating\CalendarAccess;
use OCA\Mcp\Modules\Calendar\Locating\CalendarStore;
use OCA\Mcp\Modules\Calendar\Locating\Classification;
use OCA\Mcp\Modules\Calendar\Locating\EventRepository;
use OCA\Mcp\Modules\Calendar\Locating\ScopedCalendarAccess;
use OCA\Mcp\Access\Visibility\OutputReferenceFilter;
use OCA\Mcp\Modules\Deck\Integration\DeckGatewayInterface;
use OCA\Mcp\Modules\Talk\Presentation\ActorNames;
use OCA\Mcp\Modules\Talk\Attachments\AttachmentAccess;
use OCA\Mcp\Modules\Talk\Attachments\AttachmentMessage;
use OCA\Mcp\Modules\Talk\Reading\ConversationReader;
use OCA\Mcp\Modules\Talk\Locating\ConversationResolver;
use OCA\Mcp\Modules\Talk\Writing\ConversationWriter;
use OCA\Mcp\Modules\Talk\Attachments\FileSharer;
use OCA\Mcp\Modules\Talk\Attachments\AttachedFile;
use OCA\Mcp\Modules\Talk\Groups\GroupCreator;
use OCA\Mcp\Modules\Talk\ReferenceLinker;
use OCA\Mcp\Modules\Talk\TalkModule;
use OCA\Mcp\Modules\Talk\Integration\TalkServices;
use OCA\Mcp\Modules\Talk\Integration\UnreadCounter;
use OCA\Mcp\Modules\Talk\Locating\UserConversationResolver;
use OCA\Mcp\Modules\Talk\Locating\UserFileResolver;
use OCA\Mcp\Modules\Talk\Writing\WritePreview;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/TalkFakes.php';

/** Talk's real module over native room creation, participants, messages and shares with observable state. */
final class TalkWritesWiring {
	public TalkChat $chat;
	/** @var list<array{level:string,message:string,context:array}> observed module logs */
	public array $logged = [];
	public HiddenTagFiles $files;
	public RecordingShares $shares;
	public TalkCreationNative $creation;
	private IManager $shareManager;
	private IUserManager $users;

	/**
	 * @param TestCase $test owner of mocks
	 * @param EffectsGate $gate endpoint
	 */
	public function __construct(private TestCase $test, private EffectsGate $gate) {
		TalkChat::install();
		$this->chat = new TalkChat(['abcd1234' => 'Project']);
		$this->files = new HiddenTagFiles($test, $gate);
		$this->files->tree->addFile('/alice/files/report.pdf', 'report', 'application/pdf');
		$this->shares = new RecordingShares($test);
		$this->shareManager = $this->shares->manager();
		$this->shareManager->method('currentUserCanEnumerateTargetUser')->willReturn(true);
		$this->shareManager->method('getShareById')->willReturnCallback(function (string $id): IShare {
			foreach ($this->shares->shares(IShare::TYPE_ROOM) as $share) {
				if ($id === 'ocRoomShare:' . $share->getId()) {
					return $share;
				}
			}
			throw new \RuntimeException('Unknown share');
		});
		$this->users = FakeUsers::manager($test, FakeUsers::DEFAULTS);
		$this->creation = new TalkCreationNative($this->chat);
	}

	/** @return TalkModule the module of one request, built as the container autowires it */
	public function module(): TalkModule {
		$apps = $this->mock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$services = $this->chat->services();
		$services['OCA\\Talk\\Service\\RoomService'] = $this->creation;
		$services['OCA\\Talk\\Service\\ParticipantService'] = $this->creation;
		$container = $this->mock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id]
			?? throw new \RuntimeException('Unexpected service ' . $id));
		$talk = new TalkServices($apps, $container, $this->users);
		$time = $this->mock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): \DateTime => new \DateTime('2026-01-02 03:04:05', new \DateTimeZone('UTC')));
		$urls = $this->mock(IURLGenerator::class);
		$visibility = $this->files->visibility();
		$references = new OutputReferenceFilter($this->files->root(), $this->shareManager, $this->gate->config(), $urls,
			$visibility, $this->files->referenceScope());
		$attachments = new AttachmentAccess($this->shareManager, $visibility, $references);
		$writer = new ConversationWriter($talk, $time, new AttachmentMessage(), $attachments);
		$sharer = new FileSharer($this->shareManager, new UserFileResolver($this->files->root(), $visibility, $references), $writer, new AttachedFile($this->files->root()));
		$resolver = new ConversationResolver($talk);
		$names = new ActorNames($this->users);
		$logger = $this->mock(LoggerInterface::class);
		foreach (['error', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(function (string|\Stringable $message, array $context = []) use ($level): void {
				$this->logged[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
			});
		}
		$userConversations = new UserConversationResolver($talk, $this->users, $this->shareManager);
		$store = $this->mock(CalendarStore::class);
		$classification = new Classification();
		$linker = \OCA\Mcp\Tests\Unit\Tools\Talk\ReferenceLinkers::build($this->mock(DeckGatewayInterface::class),
			new ScopedCalendarAccess($this->mock(CalendarAccess::class), $this->gate->policy()), $store,
			new EventRepository($store, $classification), $classification, $apps, $this->users, $this->gate->policy(), $urls);
		return \OCA\Mcp\Tests\Unit\Tools\Talk\TalkTestModule::build($talk, new ConversationReader($talk, $resolver, $names, $references, new UnreadCounter($this->users)), $resolver, $writer, $sharer,
			new WritePreview($writer, $sharer, $attachments, $names), $userConversations,
			new GroupCreator($talk, $this->users, $userConversations, $logger), $linker, $logger);
	}

	/** @return void an attachment already in the room, so quoting creates only messages */
	public function seedAttachment(): void {
		$share = $this->shareManager->newShare();
		$share->setNode($this->files->tree->node('/alice/files/report.pdf'));
		$share->setShareType(IShare::TYPE_ROOM);
		$share->setSharedWith('abcd1234');
		$share->setSharedBy('alice');
		$share->setPermissions(1);
		$this->shareManager->createShare($share);
	}

	/** @return array<string, mixed> native messages, room creations and invited participants */
	public function state(): array {
		return ['messages' => $this->chat->messages, 'rooms' => array_keys($this->chat->rooms),
			'created' => $this->creation->created, 'invited' => $this->creation->invited, 'shares' => $this->shares->view()];
	}

	/**
	 * @param class-string $class type
	 * @return \PHPUnit\Framework\MockObject\MockObject mock
	 */
	private function mock(string $class): \PHPUnit\Framework\MockObject\MockObject {
		return (new \ReflectionMethod($this->test, 'createMock'))->invoke($this->test, $class);
	}
}
