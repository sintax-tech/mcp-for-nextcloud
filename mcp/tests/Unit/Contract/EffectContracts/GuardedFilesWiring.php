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
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use OCA\Mcp\Tests\Unit\Tools\Files\AbsentFileTypePolicy;
use OCA\Mcp\Tests\Unit\Tools\Files\FakeFilenameValidator;
use OCA\Mcp\Tests\Unit\Access\FileTypes\FileTypePolicyFixture;
use OCA\Mcp\Access\FileTypes\FileTypeGuard;
use OCA\Mcp\Platform\FileAccess\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\ActorRoot;
use OCA\Mcp\Modules\Files\ContentTypeGate;
use OCA\Mcp\Modules\Files\Deletion\DeleteBackendGate;
use OCA\Mcp\Modules\Files\FilesModule;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Encryption\IManager as EncryptionManager;
use OCP\Files\Folder;
use OCP\Files\IFilenameValidator;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Share\IManager as IShareManager;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

require_once __DIR__ . '/../../Tools/Files/Deletion/LegacyBackendFixture.php';

/**
 * Builds the Files module of one request as the container autowires it (no registerService factory), like
 * {@see FilesContractWiring}, over a tree and a share manager the contract chooses, with three switches the Files
 * contracts of deletion and sharing need: the exclusion tag can be configured or not ({@see self::$excluding}: folders
 * cannot be deleted while one is), the nodes in {@see self::$hidden} carry it, and Guests can hide external storage from
 * alice ({@see self::$guestHidesExternal}). The deletion backend gate is the real one over an instance where the trash
 * bin app is enabled, encryption is off, file locking is on, the selected trash backend is the legacy one and no trash
 * size limit is set.
 */
final class GuardedFilesWiring {
	/** Id of the exclusion tag. */
	public const HIDDEN_TAG = '999';
	/** Fixed clock of every request. */
	public const NOW = 1790000000;

	/** @var list<int> file ids carrying the exclusion tag */
	public array $hidden = [];
	/** Whether Guests hides external storage from alice: a guest while "allow external storage" is off. */
	public bool $guestHidesExternal = false;
	/** @var array<class-string, object> services of the request being built, shared like container singletons */
	private array $request = [];

	/**
	 * @param TestCase $test owner of the mocks
	 * @param EffectsGate $gate the endpoint, whose configuration and policy the module reads
	 * @param \Closure(): Folder $home alice's folder as the tree holds it now
	 * @param \Closure(): IRootFolder $root the filesystem root above it
	 * @param IShareManager $shares the share manager of the contract
	 * @param bool $excluding whether the exclusion tag starts configured
	 */
	public function __construct(private TestCase $test, private EffectsGate $gate, private \Closure $home, private \Closure $root,
		private IShareManager $shares, bool $excluding = true) {
		$this->exclude($excluding);
	}

	/**
	 * @param bool $on whether the administrator configured the exclusion tag from now on
	 * @return void
	 */
	public function exclude(bool $on): void {
		if ($on) {
			$this->gate->data()->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode([self::HIDDEN_TAG]);
		} else {
			unset($this->gate->data()->app['mcp'][VisibilityGuard::CONFIG_KEY]);
		}
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
		$root = ($this->root)();
		return match ($class) {
			\Psr\Container\ContainerInterface::class => $this->container(),
			IConfig::class => $this->gate->config(),
			GrantPolicy::class, ScopeReader::class => $this->gate->policy(),
			IShareManager::class => $this->shares,
			IRootFolder::class => $root,
			ActorRoot::class => FilesActorRootFixture::create($this->test, ($this->home)()),
			VisibilityGuard::class, FilesAccessGuard::class => new FilesAccessGuard($this->gate->config(), $this->tagMapper(), $root,
				$this->tagManager(), null, null, $this->resolve(FilesScope::class), $this->guests()),
			FilesScope::class => new FilesScope($this->gate->policy(), new VisibilityGuard($this->gate->config(), $this->tagMapper(), $root,
				$this->tagManager()), $root),
			DeleteBackendGate::class => $this->trashGate(),
			ISystemTagObjectMapper::class => $this->tagMapper(),
			ISystemTagManager::class => $this->tagManager(),
			IUserManager::class => FakeUsers::manager($this->test, FakeUsers::DEFAULTS + ['bruno' => 'Bruno Lima']),
			IGroupManager::class => $this->stub(IGroupManager::class, ['getUserGroupIds' => []]),
			IAppManager::class => $this->stub(IAppManager::class, ['isEnabledForUser' => true]),
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

	/**
	 * The real deletion backend gate over an eligible instance: the trash manager selects the legacy backend for every
	 * storage, so whether a node can go to the trash is decided by its own storage (the trash wrapper or not).
	 *
	 * @return DeleteBackendGate the gate of this request
	 */
	private function trashGate(): DeleteBackendGate {
		$manager = new class() {
			/** @return object the backend the trash manager selects for any storage: the legacy one */
			public function getBackendForStorage(): object {
				return new \OCA\Files_Trashbin\Trash\LegacyTrashBackend();
			}
		};
		$container = $this->mock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $id === 'OCA\\Files_Trashbin\\Trash\\ITrashManager'
			? $manager : throw new \LogicException($id));
		$config = $this->mock(IConfig::class);
		$config->method('getSystemValueBool')->willReturnCallback(static fn (string $key, bool $default = false): bool => $default);
		$config->method('getUserValue')->willReturnCallback(static fn ($uid, $app, $key, $default = '') => $default);
		return new DeleteBackendGate($this->stub(IAppManager::class, ['isEnabledForUser' => true]),
			FakeUsers::manager($this->test, FakeUsers::DEFAULTS), $this->stub(EncryptionManager::class, ['isEnabled' => false]),
			$this->stub(IRequest::class, ['getHeader' => '']), $this->gate->data()->appConfig($this->test), $config,
			$this->mock(ILockingProvider::class), $container);
	}

	/** @return ISystemTagObjectMapper the tags of every node: the exclusion tag on {@see self::$hidden} */
	private function tagMapper(): ISystemTagObjectMapper {
		$tags = $this->mock(ISystemTagObjectMapper::class);
		$tags->method('getTagIdsForObjects')->willReturnCallback(fn (array $ids): array => array_combine(
			array_map('strval', $ids),
			array_map(fn ($id): array => in_array((int)$id, $this->hidden, true) ? [self::HIDDEN_TAG] : [], $ids),
		));
		return $tags;
	}

	/** @return ISystemTagManager a manager that knows the exclusion tag, so the configured exclusion is valid */
	private function tagManager(): ISystemTagManager {
		$tag = $this->stub(ISystemTag::class, ['getId' => self::HIDDEN_TAG]);
		return $this->stub(ISystemTagManager::class, ['getTagsByIds' => [self::HIDDEN_TAG => $tag]]);
	}

	/** @return GuestRestrictions Guests as the Files guard asks it: whether external storage is hidden from alice */
	private function guests(): GuestRestrictions {
		$guests = $this->mock(GuestRestrictions::class);
		$guests->method('hidesExternalStorage')->willReturnCallback(fn (): bool => $this->guestHidesExternal);
		$guests->method('allowsApp')->willReturn(true);
		return $guests;
	}

	/** @return IDBConnection a connection that only escapes LIKE patterns: no flow queries the database */
	private function db(): IDBConnection {
		$db = $this->mock(IDBConnection::class);
		$db->method('escapeLikeParameter')->willReturnCallback(fn (string $s): string => addcslashes($s, '\\_%'));
		return $db;
	}

	/** @return ITimeFactory the clock stopped at {@see self::NOW} */
	private function time(): ITimeFactory {
		$time = $this->stub(ITimeFactory::class, ['getTime' => self::NOW]);
		$time->method('getDateTime')->willReturnCallback(fn (): \DateTime => new \DateTime('@' . self::NOW));
		return $time;
	}

	/**
	 * @param class-string $class type to mock
	 * @param array<string, mixed> $answers fixed return value by method name
	 * @return MockObject the mock answering them
	 */
	private function stub(string $class, array $answers): MockObject {
		$mock = $this->mock($class);
		foreach ($answers as $method => $value) {
			$mock->method($method)->willReturn($value);
		}
		return $mock;
	}

	/**
	 * @param class-string $class type to mock
	 * @return MockObject mock owned by the running test
	 */
	private function mock(string $class): MockObject {
		return (new \ReflectionMethod($this->test, 'createMock'))->invoke($this->test, $class);
	}
}
