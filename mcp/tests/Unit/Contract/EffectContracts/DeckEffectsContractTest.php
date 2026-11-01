<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Deck\Service\BoardService;
use OCA\Deck\Sharing\DeckShareProvider;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;
use OCA\Mcp\Tools\Deck\DeckGatewayInterface;
use OCA\Mcp\Tools\Deck\DeckToolModule;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

require_once __DIR__ . '/../../Tools/Deck/Stubs/deck_stubs.php';

/**
 * Effect contract of the Deck module (RE-01): the label change of a card and the link of a Nextcloud file to a card,
 * frozen through the endpoint. The module is built as the container autowires it; its native Deck boundary is a
 * stateful gateway double over one board ({@see DeckBoardDouble}), the share manager a ledger of the shares created
 * ({@see RecordingShares}), and the file a node of a fake tree behind the real Files gate and its hidden tag.
 */
final class DeckEffectsContractTest extends TestCase {
	/** Path of the file the link flow attaches. */
	private const FILE = '/alice/files/Propostas/Orcamento.pdf';

	private EffectsGate $gate;
	private DeckBoardDouble $board;
	private DeckGatewayInterface $gateway;
	private HiddenTagFiles $files;
	private RecordingShares $shares;
	private IManager $shareManager;
	private IUserManager $users;

	/** @return void builds the board, the tree, the share ledger and the endpoint */
	protected function setUp(): void {
		$this->gate = new EffectsGate($this);
		$this->board = new DeckBoardDouble($this);
		$this->gateway = $this->board->gateway();
		$this->files = new HiddenTagFiles($this, $this->gate);
		$this->files->tree->addFile(self::FILE, '%PDF-1.7', 'application/pdf');
		$this->shares = new RecordingShares($this);
		$this->shareManager = $this->shares->manager();
		$this->users = FakeUsers::manager($this, FakeUsers::DEFAULTS);
		$this->gate->serve(fn (): array => [$this->module()]);
	}

	/** @return DeckToolModule the module of one request, every optional collaborator present as the container autowires it */
	private function module(): DeckToolModule {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_767_323_045);
		$time->method('getDateTime')->willReturnCallback(static fn (): \DateTime => new \DateTime('2026-01-02 03:04:05', new \DateTimeZone('UTC')));
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://cloud.example.com/apps/deck/');
		$session = $this->createMock(ISession::class);
		$session->method('get')->willReturnCallback(static fn (string $key): ?string => $key === 'user_id' ? EffectsGate::USER : null);
		$module = new DeckToolModule($this->container($apps, $session), $apps, $this->createMock(LoggerInterface::class), $urls,
			$time, $this->users, $this->gate->config(), $session);
		// The native Deck services are replaced at their boundary, as the Deck unit tests do.
		(new ReflectionProperty($module, 'gateway'))->setValue($module, $this->gateway);
		return $module;
	}

	/**
	 * @param IAppManager $apps app manager of the request (Guests and Circles disabled)
	 * @param ISession $session the session the native Deck services read
	 * @return ContainerInterface the server container as the file-link tool resolves it
	 */
	private function container(IAppManager $apps, ISession $session): ContainerInterface {
		$access = new NodeAccessInfo($this->users, $this->files->tree->shareManager());
		$guard = $this->files->access();
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturnCallback(fn () => $this->users->get(EffectsGate::USER));
		$provider = $this->createMock(DeckShareProvider::class);
		$provider->method('getSharesByPath')->willReturnCallback(fn (File $file): array => array_values(array_filter(
			$this->shares->shares(IShare::TYPE_DECK), static fn (IShare $share): bool => $share->getNodeId() === $file->getId())));
		// The board has no ACL entry: alice, its owner, is its only member.
		$acls = new class {
			/**
			 * @param int $boardId board whose sharing entries are read
			 * @return list<object> its entries: none
			 */
			public function findAll(int $boardId): array {
				return [];
			}
		};
		$services = [
			GrantPolicy::class => $this->gate->policy(), FilesAccessGuard::class => $guard, IManager::class => $this->shareManager,
			IConfig::class => $this->gate->config(), IAppManager::class => $apps, IUserManager::class => $this->users,
			IUserSession::class => $userSession, IGroupManager::class => $this->createMock(IGroupManager::class),
			IRootFolder::class => $this->files->root(), NodeAccessInfo::class => $access,
			SharedWriteGuard::class => new SharedWriteGuard($access), ISession::class => $session,
			BoardService::class => $this->createMock(BoardService::class), DeckShareProvider::class => $provider,
			'OCA\\Deck\\Db\\AclMapper' => $acls,
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id]
			?? throw new \RuntimeException('Unexpected service ' . $id));
		return $container;
	}

	/** @return void deck_edit_card_labels on a card: plan, strict confirm, PlanChanged, effect and every refusal */
	public function testLabelChangeOfACard(): void {
		$flow = (new CriticalFlow('edit-card-labels', 'deck_edit_card_labels', 'deck', 'edit',
			['cardId' => 7, 'add' => [2], 'remove' => [1]],
			fn (): array => ['labels' => $this->board->labels(), 'writes' => $this->board->writes]))
			// Another member renames a requested label after the plan: the label the user approved is not the one now.
			->changing(function (): void {
				$this->board->catalog[2]['title'] = 'Revisão jurídica';
			})
			->withoutVisibility('A Deck card has no exclusion of its own: the hidden system tag applies to Files nodes, and '
				. 'deck_edit_card_labels touches no file; access to the card is the Deck ACL, read by the gateway.');
		(new FlowRunner($this, $this->gate, 'deck'))->run($flow);
	}

	/** @return void deck_link_card_file of a visible file: plan, strict confirm, PlanChanged, effect and every refusal */
	public function testLinkOfAFileToACard(): void {
		$path = self::FILE;
		$id = $this->files->tree->nodes[$path]['id'];
		// A card holds one link per file, so a later step links the same file to the next card still without it:
		// each refusal is then asked with a confirmation that would succeed right now.
		$flow = (new CriticalFlow('link-card-file', 'deck_link_card_file', 'deck', 'edit',
			fn (): array => ['cardId' => $this->unlinkedCard(), 'fileId' => $id],
			fn (): array => ['shares' => $this->shares->view(), 'labels' => $this->board->labels()]))
			// The file is changed by another client after the plan: its new ETag is not the version the user approved.
			->changing(function () use ($path): void {
				$this->files->tree->nodes[$path]['etag'] = 'e-changed';
				$this->files->tree->nodes[$path]['content'] .= ' rev. 2';
			})
			->hiding(function () use ($id): void {
				$this->files->hidden = [$id];
			}, function (): void {
				$this->files->hidden = [];
			});
		(new FlowRunner($this, $this->gate, 'deck'))->run($flow);
	}

	/** @return int the first card of the board the file is not linked to yet */
	private function unlinkedCard(): int {
		$linked = array_map(static fn (IShare $share): int => (int)$share->getSharedWith(), $this->shares->shares(IShare::TYPE_DECK));
		foreach (array_keys($this->board->cards) as $cardId) {
			if (!in_array($cardId, $linked, true)) {
				return $cardId;
			}
		}
		throw new \LogicException('every card is linked');
	}
}
