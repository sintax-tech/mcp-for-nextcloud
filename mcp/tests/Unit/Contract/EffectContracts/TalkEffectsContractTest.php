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
use OCA\Mcp\Tools\Calendar\CalendarAccess;
use OCA\Mcp\Tools\Calendar\CalendarStore;
use OCA\Mcp\Tools\Calendar\Classification;
use OCA\Mcp\Tools\Calendar\EventRepository;
use OCA\Mcp\Tools\Calendar\ScopedCalendarAccess;
use OCA\Mcp\Tools\Common\OutputReferenceFilter;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Talk\ActorNames;
use OCA\Mcp\Tools\Talk\AttachmentAccess;
use OCA\Mcp\Tools\Talk\AttachmentMessage;
use OCA\Mcp\Tools\Talk\ConversationReader;
use OCA\Mcp\Tools\Talk\ConversationResolver;
use OCA\Mcp\Tools\Talk\ConversationWriter;
use OCA\Mcp\Tools\Talk\FileSharer;
use OCA\Mcp\Tools\Talk\AttachedFile;
use OCA\Mcp\Tools\Talk\GroupCreator;
use OCA\Mcp\Tools\Talk\ReferenceLinker;
use OCA\Mcp\Tools\Talk\TalkModule;
use OCA\Mcp\Tools\Talk\TalkServices;
use OCA\Mcp\Tools\Talk\UserConversationResolver;
use OCA\Mcp\Tools\Talk\UserFileResolver;
use OCA\Mcp\Tools\Talk\WritePreview;
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

/**
 * Effect contract of the Talk module (RE-01): a reply in a conversation and the attachment of a file to it, frozen
 * through the endpoint. The module and every collaborator of it are the real classes the container autowires; only the
 * Talk app is faked ({@see TalkChat}, a chat of two rooms that keeps the messages sent), with the share manager a ledger
 * of the room shares created ({@see RecordingShares}) and the file a node of a fake tree behind the hidden tag.
 *
 * Each test runs in a process of its own: the fake Talk classes are aliased to the `OCA\Talk\*` names, which the other
 * Talk tests need absent.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TalkEffectsContractTest extends TestCase {
	/** Path of the file the attachment flow shares. */
	private const FILE = '/alice/files/Relatorios/Q3.pdf';

	private EffectsGate $gate;
	private TalkChat $chat;
	private HiddenTagFiles $files;
	private RecordingShares $shares;
	private IManager $shareManager;
	private IUserManager $users;

	/** @return void builds the chat, the tree, the share ledger and the endpoint */
	protected function setUp(): void {
		TalkChat::install();
		$this->gate = new EffectsGate($this);
		$this->chat = new TalkChat(['abcd1234' => 'Projeto Alfa', 'efgh5678' => 'Diretoria']);
		$this->files = new HiddenTagFiles($this, $this->gate);
		$this->files->tree->addFile(self::FILE, '%PDF-1.7 Q3', 'application/pdf');
		$this->shares = new RecordingShares($this);
		$this->shareManager = $this->shares->manager();
		$this->users = FakeUsers::manager($this, FakeUsers::DEFAULTS);
		$this->gate->serve(fn (): array => [$this->module()]);
	}

	/** @return TalkModule the module of one request, built as the container autowires it */
	private function module(): TalkModule {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$services = $this->chat->services();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id]
			?? throw new \RuntimeException('Unexpected service ' . $id));
		$talk = new TalkServices($apps, $container, $this->users);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): \DateTime => new \DateTime('2026-01-02 03:04:05', new \DateTimeZone('UTC')));
		$urls = $this->createMock(IURLGenerator::class);
		$visibility = $this->files->visibility();
		$references = new OutputReferenceFilter($this->files->root(), $this->shareManager, $this->gate->config(), $urls,
			$visibility, $this->files->referenceScope());
		$attachments = new AttachmentAccess($this->shareManager, $visibility, $references);
		$writer = new ConversationWriter($talk, $time, new AttachmentMessage(), $attachments);
		$sharer = new FileSharer($this->shareManager, new UserFileResolver($this->files->root(), $visibility, $references), $writer, new AttachedFile($this->files->root()));
		$resolver = new ConversationResolver($talk);
		$names = new ActorNames($this->users);
		$logger = $this->createMock(LoggerInterface::class);
		$userConversations = new UserConversationResolver($talk, $this->users, $this->shareManager);
		$store = $this->createMock(CalendarStore::class);
		$classification = new Classification();
		$linker = new ReferenceLinker($this->createMock(DeckGatewayInterface::class),
			new ScopedCalendarAccess($this->createMock(CalendarAccess::class), $this->gate->policy()), $store,
			new EventRepository($store, $classification), $classification, $apps, $this->users, $this->gate->policy(), $urls);
		return new TalkModule($talk, new ConversationReader($talk, $resolver, $names, $references), $resolver, $writer, $sharer,
			new WritePreview($writer, $sharer, $attachments, $names), $userConversations,
			new GroupCreator($talk, $this->users, $userConversations, $logger), $linker, $logger);
	}

	/** @return void talk_reply in a conversation: plan, strict confirm, effect and every refusal */
	public function testReplyInAConversation(): void {
		$flow = (new CriticalFlow('reply', 'talk_reply', 'talk', 'reply',
			['conversation_token' => 'abcd1234', 'message' => 'A ata da reunião está pronta.'],
			fn (): array => ['messages' => $this->chat->messages]))
			->withoutPlanChanged('talk_reply signs no plan: the confirmed call resolves the conversation and the text again '
				. 'and sends what it finds; a message has no version an ETag or plan_state could pin.')
			->withoutVisibility('A conversation and a text message carry no Files node: the hidden system tag has nothing to '
				. 'hide on talk_reply, and access to the room is Talk membership, checked on every call.');
		(new FlowRunner($this, $this->gate, 'talk'))->run($flow);
	}

	/** @return void talk_attach_file of a visible file: plan, strict confirm, effect and every refusal */
	public function testAttachmentOfAFile(): void {
		$id = $this->files->tree->nodes[self::FILE]['id'];
		// A file is shared once per conversation, so a later step attaches it to the next conversation still without it:
		// each refusal is then asked with a confirmation that would succeed right now.
		$flow = (new CriticalFlow('attach-file', 'talk_attach_file', 'talk', 'attach',
			fn (): array => ['conversation_token' => $this->roomWithoutTheFile(), 'path' => 'Relatorios/Q3.pdf',
				'message' => 'Segue o relatório do trimestre.'],
			fn (): array => ['shares' => $this->shares->view(), 'messages' => $this->chat->messages]))
			->withoutPlanChanged('talk_attach_file signs no plan: the confirmed call resolves the path again and shares the '
				. 'file it finds there, and refuses one already shared in the conversation.')
			->hiding(function () use ($id): void {
				$this->files->hidden = [$id];
			}, function (): void {
				$this->files->hidden = [];
			});
		(new FlowRunner($this, $this->gate, 'talk'))->run($flow);
	}

	/** @return string the first conversation the file is not shared in yet */
	private function roomWithoutTheFile(): string {
		$shared = array_map(static fn (IShare $share): string => $share->getSharedWith(), $this->shares->shares(IShare::TYPE_ROOM));
		foreach (array_keys($this->chat->rooms) as $token) {
			if (!in_array($token, $shared, true)) {
				return $token;
			}
		}
		throw new \LogicException('the file is shared in every conversation');
	}
}
