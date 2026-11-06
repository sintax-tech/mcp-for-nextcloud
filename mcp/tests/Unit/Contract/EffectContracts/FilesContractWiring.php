<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Platform\Backups\BackupSettingsDocument;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Access\ResourceScope\FilesScope;
use OCA\Mcp\Access\ResourceScope\ScopeReader;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Unit\Contract\FilesActorRootFixture;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Service\BackupDocumentFixture;
use OCA\Mcp\Tests\Unit\Platform\FileAccess\FakeLockManager;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use OCA\Mcp\Tests\Unit\Tools\Files\AbsentFileTypePolicy;
use OCA\Mcp\Tests\Unit\Tools\Files\FakeFilenameValidator;
use OCA\Mcp\Tests\Unit\Tools\Files\Sharing\FakeShares;
use OCA\Mcp\Tests\Unit\Access\FileTypes\FileTypePolicyFixture;
use OCA\Mcp\Access\FileTypes\FileTypeGuard;
use OCA\Mcp\Platform\FileAccess\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\ActorRoot;
use OCA\Mcp\Modules\Files\ContentTypeGate;
use OCA\Mcp\Modules\Files\FilesModule;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IFilenameValidator;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Builds the Files module of one request the way the container autowires it (FilesModule has no registerService
 * factory): every constructor parameter, optional ones included, resolved by type; app classes are built recursively,
 * the Nextcloud services are the persistent fakes of the contract (FakeTree, FakeShares, a hidden system tag) or plain
 * mocks where the flow never reaches them. The visibility gate is the real FilesAccessGuard behind the hidden tag, over the
 * real FilesScope of the operation EffectsGate opens per request, with a Guests double.
 */
final class FilesContractWiring {
	/** Id of the hidden system tag. */
	public const HIDDEN_TAG = '999';
	/** Fixed clock of every request, so nothing in a plan depends on the moment of the run. */
	public const NOW = 1790000000;

	/** @var list<int> file ids carrying the hidden tag */
	public array $hidden = [];
	/** @var array<class-string, object> services of the request being built, shared like container singletons */
	private array $request = [];

	/**
	 * @param TestCase $test owner of the mocks
	 * @param EffectsGate $gate the endpoint, whose configuration and policy the module reads
	 * @param FakeTree $tree the files of alice
	 * @param FakeShares $shares the share store behind IShareManager
	 */
	public function __construct(private TestCase $test, private EffectsGate $gate, private FakeTree $tree, private FakeShares $shares) {
		$gate->data()->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode([self::HIDDEN_TAG]);
	}

	/** @return FilesModule the module of one request, with fresh per-request services over the persistent fakes */
	public function module(): FilesModule {
		$this->request = [];
		$module = $this->build(FilesModule::class);
		assert($module instanceof FilesModule);
		return $module;
	}

	/**
	 * @param class-string $class type to resolve
	 * @return object the instance of this request for that type
	 */
	private function resolve(string $class): object {
		return $this->request[$class] ??= $this->known($class) ?? $this->build($class);
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
			\Psr\Container\ContainerInterface::class => $this->container(),
			IConfig::class => $this->gate->config(),
			GrantPolicy::class, ScopeReader::class => $this->gate->policy(),
			IShareManager::class => $this->shares->manager(),
			IRootFolder::class => $this->tree->filesystemRoot(),
			ActorRoot::class => FilesActorRootFixture::create($this->test, $this->tree->rootFolder()),
			// The Files gate as the container autowires it: the plain guard behind the operation user's FilesScope.
			VisibilityGuard::class, FilesAccessGuard::class => new FilesAccessGuard($this->gate->config(), $this->tagMapper(),
				$this->tree->filesystemRoot(), $this->tagManager(), null, null, $this->resolve(FilesScope::class), $this->guests()),
			FilesScope::class => new FilesScope($this->gate->policy(), new VisibilityGuard($this->gate->config(), $this->tagMapper(),
				$this->tree->filesystemRoot(), $this->tagManager()), $this->tree->filesystemRoot()),
			ISystemTagObjectMapper::class => $this->tagMapper(),
			ISystemTagManager::class => $this->tagManager(),
			IUserManager::class => FakeUsers::manager($this->test, FakeUsers::DEFAULTS + ['bruno' => 'Bruno Lima']),
			IGroupManager::class => $this->groups(),
			IAppManager::class => $this->apps(),
			IDBConnection::class => $this->db(),
			ITimeFactory::class => $this->time(),
			LockAwareWrite::class => (new FakeLockManager())->service($this->test),
			FileTypeGuard::class => FileTypePolicyFixture::off($this->test),
			ContentTypeGate::class => AbsentFileTypePolicy::gate($this->test),
			IFilenameValidator::class => new FakeFilenameValidator(),
			BackupSettingsDocument::class => BackupDocumentFixture::fromConfig($this->test, $this->gate->data()->appConfig($this->test)),
			default => null,
		};
	}

	/** @return \Psr\Container\ContainerInterface container sharing this request's resolved doubles */
	private function container(): \Psr\Container\ContainerInterface {
		$container = $this->mock(\Psr\Container\ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id): object => $this->resolve($id));
		$container->method('has')->willReturnCallback(static fn (string $id): bool => class_exists($id) || interface_exists($id));
		return $container;
	}

	/** @return ISystemTagObjectMapper the tags of every node: the hidden tag on {@see self::$hidden} */
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
	 * The guard asks Guests only whether it hides external storage. The gate's own guest list is private to it, so this
	 * double answers as for a regular account; the tree has no external mount, so the answer cannot change any step.
	 *
	 * @return GuestRestrictions Guests hiding no storage and allowing every app
	 */
	private function guests(): GuestRestrictions {
		$guests = $this->mock(GuestRestrictions::class);
		$guests->method('hidesExternalStorage')->willReturn(false);
		$guests->method('allowsApp')->willReturn(true);
		return $guests;
	}

	/** @return IGroupManager no groups: the flows share with users only */
	private function groups(): IGroupManager {
		$groups = $this->mock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn([]);
		return $groups;
	}

	/** @return IAppManager every app enabled for alice */
	private function apps(): IAppManager {
		$apps = $this->mock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		return $apps;
	}

	/** @return IDBConnection a connection that only escapes LIKE patterns: no flow queries the database */
	private function db(): IDBConnection {
		$db = $this->mock(IDBConnection::class);
		$db->method('escapeLikeParameter')->willReturnCallback(fn (string $s): string => addcslashes($s, '\\_%'));
		return $db;
	}

	/** @return ITimeFactory the clock stopped at {@see self::NOW} */
	private function time(): ITimeFactory {
		$time = $this->mock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$time->method('getDateTime')->willReturnCallback(fn (): \DateTime => new \DateTime('@' . self::NOW));
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
