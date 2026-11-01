<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Deck\Sharing\DeckShareProvider;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\Scope\FilesAccessGuard;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;
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
use ReflectionMethod;

require_once __DIR__ . '/../../Tools/Deck/Stubs/deck_stubs.php';

/**
 * The Deck module of one request over the REAL gateway, with every service the file-link tool resolves: the module
 * builds its own {@see \OCA\Mcp\Tools\Deck\DeckServiceGateway} as the container registers it, and the container
 * hands out the native Deck services over {@see NativeDeck}, the real Files gate over a tree with a hidden system tag
 * ({@see HiddenTagFiles}) and a share manager whose Deck shares are a ledger ({@see RecordingShares}).
 */
final class DeckGatewayWiring {
	/** Path of the file the link flow attaches. */
	public const FILE = '/alice/files/Propostas/Orcamento.pdf';

	public readonly NativeDeck $deck;
	/** Whether this scene installs the native dependency capability. */
	public bool $dependencies = false;
	public readonly HiddenTagFiles $files;
	public readonly RecordingShares $shares;
	private IManager $shareManager;
	private IUserManager $users;

	/**
	 * @param TestCase $test the running test, owner of the mocks
	 * @param EffectsGate $gate the endpoint, whose policy and configuration the module reads
	 */
	public function __construct(private TestCase $test, private EffectsGate $gate) {
		$this->deck = new NativeDeck();
		$this->files = new HiddenTagFiles($test, $gate);
		$this->files->tree->addFile(self::FILE, '%PDF-1.7', 'application/pdf');
		$this->shares = new RecordingShares($test);
		$this->shareManager = $this->shares->manager();
		$this->users = FakeUsers::manager($test, FakeUsers::DEFAULTS);
	}

	/** @return DeckToolModule the module of one request, every optional collaborator present as the container autowires it */
	public function module(): DeckToolModule {
		$apps = $this->mock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$apps->method('getAppVersion')->willReturn($this->dependencies ? '1.19.0' : '');
		$apps->method('isEnabledForAnyone')->willReturnCallback(static fn (string $app): bool => $app === 'deck');
		$time = $this->mock(ITimeFactory::class);
		$time->method('getTime')->willReturn(NativeDeck::NOW);
		$time->method('getDateTime')->willReturnCallback(static fn (): \DateTime => new \DateTime('@' . NativeDeck::NOW));
		$urls = $this->mock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://cloud.example.com/apps/deck/');
		$session = $this->mock(ISession::class);
		$session->method('get')->willReturnCallback(static fn (string $key): ?string => $key === 'user_id' ? EffectsGate::USER : null);
		return new DeckToolModule($this->container($apps, $session), $apps, $this->mock(LoggerInterface::class), $urls, $time,
			$this->users, $this->gate->config(), $session, $this->dependencies ? new \OCA\Mcp\Tools\Deck\DeckCapabilities($apps, 35) : null);
	}

	/**
	 * @param IAppManager $apps app manager of the request (Guests and Circles disabled)
	 * @param ISession $session the session the native Deck services read
	 * @return ContainerInterface the server container: native Deck services plus what the file-link tool resolves
	 */
	private function container(IAppManager $apps, ISession $session): ContainerInterface {
		$access = new NodeAccessInfo($this->users, $this->files->tree->shareManager());
		$userSession = $this->mock(IUserSession::class);
		$userSession->method('getUser')->willReturnCallback(fn () => $this->users->get(EffectsGate::USER));
		$provider = $this->mock(DeckShareProvider::class);
		$provider->method('getShareById')->willReturnCallback(fn (string $id) => $this->shareManager->getShareById($id));
		$provider->method('getSharesByPath')->willReturnCallback(fn (File $file): array => array_values(array_filter(
			$this->shares->shares(IShare::TYPE_DECK), static fn (IShare $share): bool => $share->getNodeId() === $file->getId())));
		// No ACL entry on the boards of alice: she, their owner, is their only member.
		$acls = new class {
			/**
			 * @param int $boardId board whose sharing entries are read
			 * @return list<object> its entries: none
			 */
			public function findAll(int $boardId): array {
				return [];
			}
		};
		$services = NativeDeckServices::of($this->deck, $this->users) + [
			GrantPolicy::class => $this->gate->policy(), FilesAccessGuard::class => $this->files->access(),
			IManager::class => $this->shareManager, IConfig::class => $this->gate->config(), IAppManager::class => $apps,
			IUserSession::class => $userSession, IGroupManager::class => $this->mock(IGroupManager::class),
			IRootFolder::class => $this->files->root(), NodeAccessInfo::class => $access,
			SharedWriteGuard::class => new SharedWriteGuard($access), ISession::class => $session,
			DeckShareProvider::class => $provider, 'OCA\\Deck\\Db\\AclMapper' => $acls,
		];
		$services['OCA\Deck\Service\AttachmentService'] = new class($this->shareManager) extends \OCA\Deck\Service\AttachmentService {
			/** @param IManager $shares native share ledger */
			public function __construct(private IManager $shares) {
			}
			/** {@inheritDoc} */
			public function delete(int $cardId, int $attachmentId, string $type = 'deck_file'): \OCA\Deck\Db\Attachment {
				if ($type !== 'file') {
					throw new \RuntimeException('Legacy deletion is forbidden');
				}
				$this->shares->deleteShare($this->shares->getShareById((string)$attachmentId));
				return new \OCA\Deck\Db\Attachment();
			}
		};

		$container = $this->mock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id]
			?? throw new \RuntimeException('Unexpected service ' . $id));
		return $container;
	}

	/**
	 * @param class-string $class type to mock
	 * @return \PHPUnit\Framework\MockObject\MockObject mock owned by the running test
	 */
	private function mock(string $class): \PHPUnit\Framework\MockObject\MockObject {
		return (new ReflectionMethod($this->test, 'createMock'))->invoke($this->test, $class);
	}
}
