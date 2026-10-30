<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Checkout\CheckoutTokenStore;
use OCA\Mcp\Service\BackupSettingsDocument;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\GuestRestrictions;
use OCA\Mcp\Service\Scope\FilesAccessGuard;
use OCA\Mcp\Service\Scope\FilesScope;
use OCA\Mcp\Service\Scope\ScopeReader;
use OCA\Mcp\Service\VisibilityGuard;
use OCA\Mcp\Tests\Unit\Checkout\InMemoryCheckoutTokenStore;
use OCA\Mcp\Tests\Unit\Contract\FilesActorRootFixture;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Service\BackupDocumentFixture;
use OCA\Mcp\Tests\Unit\Tools\Common\FakeLockManager;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use OCA\Mcp\Tests\Unit\Tools\Files\AbsentFileTypePolicy;
use OCA\Mcp\Tests\Unit\Tools\Files\FakeFilenameValidator;
use OCA\Mcp\Tests\Unit\Tools\Files\InMemoryBatchStore;
use OCA\Mcp\Tests\Unit\Tools\FileTypePolicyFixture;
use OCA\Mcp\Tools\Common\FileTypeGuard;
use OCA\Mcp\Tools\Common\LockAwareWrite;
use OCA\Mcp\Tools\Files\Access\ActorRoot;
use OCA\Mcp\Tools\Files\BatchStore;
use OCA\Mcp\Tools\Files\ContentTypeGate;
use OCA\Mcp\Tools\Files\FilesModule;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IFilenameValidator;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\ITempManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The Files module and the checkout controller of one request, autowired the way the container builds them (every
 * constructor parameter resolved by type, optional ones included), over persistent fakes the reorganization, edit and
 * checkout contracts observe: the file tree, an in-memory batch store (the SQL is covered by BatchStoreTest), an
 * in-memory checkout token store, a URL generator that records every token it is handed and a clock a step can move.
 * The visibility gate is the real FilesAccessGuard behind a hidden system tag, as in {@see FilesContractWiring}.
 */
final class FilesFlowWiring {
	/** Id of the hidden system tag. */
	public const HIDDEN_TAG = '999';
	/** Instant every request starts at, so nothing in a plan or a backup name depends on the moment of the run. */
	public const NOW = 1790000000;

	/** @var list<int> file ids carrying the hidden tag */
	public array $hidden = [];
	/** Current time of every request; a step moves it to let a link expire. */
	public int $now = self::NOW;
	/** Batches files_move_batch records, persistent across requests. */
	public InMemoryBatchStore $batches;
	/** Checkout links, persistent across requests. */
	public InMemoryCheckoutTokenStore $tokens;
	/** @var list<string> plain tokens the URL generator received, in order: the only place they leave the code */
	public array $issued = [];
	/** Temporary files the controller asked for, i.e. request bodies it copied to disk. */
	public int $tempFiles = 0;
	/** @var array<class-string, object> explicit native services of a specialized contract */
	public array $services = [];
	/** Lock provider shared across requests, so a contract can lock the actual target. */
	public FakeLockManager $locks;
	/** @var array<class-string, object> services of the request being built, shared like container singletons */
	private array $request = [];

	/**
	 * @param TestCase $test owner of the mocks
	 * @param EffectsGate $gate the endpoint, whose configuration and policy the module reads
	 * @param FakeTree $tree the files of alice
	 */
	public function __construct(private TestCase $test, private EffectsGate $gate, private FakeTree $tree) {
		$gate->data()->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode([self::HIDDEN_TAG]);
		$this->batches = new InMemoryBatchStore($this->mock(IDBConnection::class));
		$this->tokens = new InMemoryCheckoutTokenStore();
		$this->locks = new FakeLockManager();
	}

	/** @return FilesModule the module of one request, with fresh per-request services over the persistent fakes */
	public function module(): FilesModule {
		$this->request = [];
		$module = $this->build(FilesModule::class);
		assert($module instanceof FilesModule);
		return $module;
	}

	/**
	 * The checkout controller of one request. The app name and the request are the router's; every other parameter is
	 * autowired like the module's, except the optional translator, left out so messages stay in English.
	 *
	 * @param IRequest $request the request of the route
	 * @param array<string, string> $route parameters the router put in the URL
	 * @param string $body raw body of the request
	 * @return RoutedCheckoutController the controller of that request
	 */
	public function controller(IRequest $request, array $route, string $body): RoutedCheckoutController {
		$this->request = [];
		$args = ['mcp', $request];
		foreach (array_slice((new \ReflectionClass(RoutedCheckoutController::class))->getConstructor()->getParameters(), 2) as $parameter) {
			$type = $parameter->getType();
			assert($type instanceof \ReflectionNamedType);
			$args[] = $parameter->getName() === 'l10n' ? null : $this->resolve($type->getName());
		}
		$controller = new RoutedCheckoutController(...$args);
		$controller->route = $route;
		$controller->staged = $body;
		return $controller;
	}

	/**
	 * @param class-string $class type to resolve
	 * @return object the instance of this request for that type
	 */
	private function resolve(string $class): object {
		return $this->request[$class] ??= $this->services[$class] ?? $this->known($class) ?? $this->build($class);
	}

	/**
	 * @param class-string $class type to construct
	 * @return object an app class built from its constructor, or a mock of an outside type no flow reaches
	 */
	private function build(string $class): object {
		$reflection = new \ReflectionClass($class);
		if (!str_starts_with($class, 'OCA\\Mcp\\') || !$reflection->isInstantiable()) {
			return $this->mock($class);
		}
		$args = [];
		foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
			$type = $parameter->getType();
			if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
				$args[] = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;
				continue;
			}
			$args[] = $this->resolve($type->getName());
		}
		return BackupDocumentFixture::construct($this->test, $class, $args, $this->gate->data());
	}

	/**
	 * @param class-string $class type to resolve
	 * @return object|null the contract's own instance for a type the flows observe, null to autowire it
	 */
	private function known(string $class): ?object {
		return match ($class) {
			IConfig::class => $this->gate->config(),
			GrantPolicy::class, ScopeReader::class => $this->gate->policy(),
			IShareManager::class => $this->tree->shareManager(),
			IRootFolder::class => $this->tree->filesystemRoot(),
			ActorRoot::class => FilesActorRootFixture::create($this->test, $this->tree->rootFolder()),
			VisibilityGuard::class, FilesAccessGuard::class => new FilesAccessGuard($this->gate->config(), $this->tagMapper(),
				$this->tree->filesystemRoot(), $this->tagManager(), null, null, $this->resolve(FilesScope::class), $this->guests()),
			FilesScope::class => new FilesScope($this->gate->policy(), new VisibilityGuard($this->gate->config(), $this->tagMapper(),
				$this->tree->filesystemRoot(), $this->tagManager()), $this->tree->filesystemRoot()),
			ISystemTagObjectMapper::class => $this->tagMapper(),
			ISystemTagManager::class => $this->tagManager(),
			IUserManager::class => FakeUsers::manager($this->test, FakeUsers::DEFAULTS),
			IAppManager::class => $this->apps(),
			GuestRestrictions::class => $this->guests(),
			BatchStore::class => $this->batches,
			CheckoutTokenStore::class => $this->tokens,
			IURLGenerator::class => $this->urls(),
			ITempManager::class => $this->temp(),
			IUserSession::class => $this->mock(IUserSession::class),
			ITimeFactory::class => $this->time(),
			LockAwareWrite::class => $this->locks->service($this->test),
			FileTypeGuard::class => FileTypePolicyFixture::off($this->test),
			ContentTypeGate::class => AbsentFileTypePolicy::gate($this->test),
			IFilenameValidator::class => new FakeFilenameValidator(),
			BackupSettingsDocument::class => BackupDocumentFixture::fromConfig($this->test, $this->gate->data()->appConfig($this->test)),
			default => null,
		};
	}

	/**
	 * @return ISystemTagObjectMapper the tags of every node: the hidden tag on {@see self::$hidden}
	 */
	private function tagMapper(): ISystemTagObjectMapper {
		$tags = $this->mock(ISystemTagObjectMapper::class);
		$tags->method('getTagIdsForObjects')->willReturnCallback(fn (array $ids): array => array_combine(
			array_map('strval', $ids),
			array_map(fn ($id): array => in_array((int)$id, $this->hidden, true) ? [self::HIDDEN_TAG] : [], $ids),
		));
		return $tags;
	}

	/** @return ISystemTagManager a manager that knows the hidden tag, so the configured exclusion is valid */
	private function tagManager(): ISystemTagManager {
		$tag = $this->mock(ISystemTag::class);
		$tag->method('getId')->willReturn(self::HIDDEN_TAG);
		$manager = $this->mock(ISystemTagManager::class);
		$manager->method('getTagsByIds')->willReturn([self::HIDDEN_TAG => $tag]);
		return $manager;
	}

	/**
	 * Guests as for a regular account: the gate's own guest list governs the endpoint, and alice is no guest on the
	 * checkout routes, which never pass through the endpoint.
	 *
	 * @return GuestRestrictions Guests hiding no storage, allowing every app and admitting the owner of a link
	 */
	private function guests(): GuestRestrictions {
		$guests = $this->mock(GuestRestrictions::class);
		$guests->method('hidesExternalStorage')->willReturn(false);
		$guests->method('allowsApp')->willReturn(true);
		$guests->method('applyToOwner')->willReturn(true);
		return $guests;
	}

	/** @return IAppManager every app enabled for alice, files_versions included */
	private function apps(): IAppManager {
		$apps = $this->mock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		return $apps;
	}

	/**
	 * @return IURLGenerator absolute route URLs that keep the token, recording it in {@see self::$issued}
	 */
	private function urls(): IURLGenerator {
		$urls = $this->mock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(function (string $route, array $args = []): string {
			$this->issued[] = (string)($args['token'] ?? '');
			return 'https://cloud.test/index.php/apps/mcp/' . $route . '/' . ($args['token'] ?? '');
		});
		return $urls;
	}

	/**
	 * @return ITempManager real temporary files, counted in {@see self::$tempFiles}
	 */
	private function temp(): ITempManager {
		$temp = $this->mock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(function (): string|false {
			$this->tempFiles++;
			return tempnam(sys_get_temp_dir(), 'mcp');
		});
		return $temp;
	}

	/**
	 * @return ITimeFactory the clock at {@see self::$now}
	 */
	private function time(): ITimeFactory {
		$time = $this->mock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$time->method('getDateTime')->willReturnCallback(fn (): \DateTime => new \DateTime('@' . $this->now));
		return $time;
	}

	/**
	 * @param class-string $class type to mock
	 * @return MockObject mock owned by the running test
	 */
	private function mock(string $class): MockObject {
		return (new \ReflectionMethod($this->test, 'createMock'))->invoke($this->test, $class);
	}
}
