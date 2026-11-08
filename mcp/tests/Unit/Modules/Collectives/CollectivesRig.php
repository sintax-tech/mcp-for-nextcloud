<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Collectives;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Guests\GuestIdentity;
use OCA\Mcp\Access\Visibility\OutputReferenceFilter;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Collectives\CollectivesModule;
use OCA\Mcp\Modules\Collectives\Integration\CollectivesAdmission;
use OCA\Mcp\Modules\Collectives\Integration\CollectivesGateway;
use OCA\Mcp\Modules\Collectives\Integration\CollectivesSurface;
use OCA\Mcp\Modules\Collectives\Integration\PageFiles;
use OCA\Mcp\Modules\Collectives\Integration\TeamsSession;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The Collectives module of one test, built from its real classes over the native doubles of {@see CollectivesScene}.
 * The switches are what the admission and the file checks read, so a test turns one condition off at a time.
 */
final class CollectivesRig {
	/** @var list<string> granted operations of the collectives module */
	public array $grants = ['read', 'create', 'edit', 'move', 'collective.create', 'members'];
	public bool $appEnabled = true;
	/** Installed Collectives version the app manager answers. */
	public string $version = '4.7.1';
	public string $sessionUser = 'alice';
	public string $folderSetting = '/.Collectives';
	/** @var list<int> file ids hidden from MCP by a system tag */
	public array $hidden = [];
	/** @var list<int> file ids outside the user's resource scope */
	public array $outOfScope = [];
	public ?CollectivesSurface $surface = null;
	/** The real policy of an effects gate; null uses $this->grants */
	public ?GrantPolicy $realPolicy = null;
	/** Installed Teams (Circles) version the app manager answers. */
	public string $teamsVersion = '35.0.0';
	/** The actor's recorded Teams identity (`circles/userSingleId`), '' when Teams never resolved them. */
	public string $teamsIdentity = 'single-alice';
	/** Whether the actor is a guest account; null makes the identification fail. */
	public ?bool $guestAccount = false;
	/** Whether the database keeps four-byte characters. */
	public bool $fourBytes = true;
	/** Classes autowiring takes from this rig instead of a blank mock. */
	private const WIRED = [IAppManager::class, IConfig::class, IUserManager::class, IDBConnection::class, IFactory::class,
		IRootFolder::class, GuestIdentity::class, ContainerInterface::class, IGroupManager::class];
	/** Accounts of this server. */
	private const ACCOUNTS = ['alice', 'bob', 'carol', 'dave', 'erin', 'frank', 'gina', 'hank', 'ivy', 'jack', 'kim'];
	/** @var array<class-string, object> instances already built */
	private array $built = [];

	/** @param TestCase $test owner of the mocks */
	public function __construct(private TestCase $test) {
		CollectivesScene::reset($test);
		TeamsScene::reset();
	}

	/** @return CollectivesModule the module, its tools resolved from the same wiring */
	public function module(): CollectivesModule {
		return new CollectivesModule($this->container());
	}

	/**
	 * @template T of object
	 * @param class-string<T> $class a module or native class
	 * @return T the instance this rig wires
	 */
	public function get(string $class): object {
		return $this->build($class);
	}

	/**
	 * @param class-string $class a module class
	 * @param object $instance what the module gets for it instead of the class this rig would build
	 * @return void
	 */
	public function bind(string $class, object $instance): void {
		$this->built[$class] = $instance;
	}

	/** @return PlanState the plan states of the module */
	public function states(): PlanState {
		return $this->build(PlanState::class);
	}

	/** @return ContainerInterface resolves module classes and the native doubles */
	private function container(): ContainerInterface {
		$container = $this->mock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $class): object => $this->build($class));
		return $container;
	}

	/**
	 * @param string $class class to resolve
	 * @return object the instance: mocks for Nextcloud and Access, real classes for the module
	 */
	private function build(string $class): object {
		if (isset($this->built[$class])) {
			return $this->built[$class];
		}
		$instance = match ($class) {
			'OCA\\Collectives\\Service\\CollectiveService', 'OCA\\Collectives\\Service\\PageService',
			'OCA\\Collectives\\Service\\SearchService', 'OCA\\Circles\\CirclesManager', 'OCA\\Collectives\\Mount\\CollectiveFolderManager',
			'OCA\\Collectives\\Db\\PageMapper' => new $class(),
			ContainerInterface::class => $this->container(),
			PlanState::class => new PlanState($this->config()),
			IAppManager::class => $this->apps(),
			CollectivesAdmission::class => new CollectivesAdmission($this->policy(), $this->apps(), $this->users(), $this->session(),
				$this->config(), $this->appConfig(), $this->surface ?? new CollectivesSurface()),
			CollectivesGateway::class => new CollectivesGateway($this->container(), $this->build(CollectivesAdmission::class), $this->build(TeamsSession::class)),
			CollectivesSurface::class => $this->surface ?? new CollectivesSurface(),
			PageFiles::class => new PageFiles($this->visibility(), $this->scope()),
			IConfig::class => $this->config(),
			IUserManager::class => $this->users(),
			GuestIdentity::class => $this->guests(),
			IDBConnection::class => $this->database(),
			IFactory::class => $this->languages(),
			IRootFolder::class => $this->storage(),
			IGroupManager::class => $this->groups(),
			default => $this->autowire($class),
		};
		return $this->built[$class] = $instance;
	}

	/** @param class-string $class module class @return object built from its constructor */
	private function autowire(string $class): object {
		$args = [];
		foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
			$type = $parameter->getType();
			$name = $type instanceof ReflectionNamedType ? $type->getName() : '';
			$args[] = in_array($name, self::WIRED, true) || (str_starts_with($name, 'OCA\\Mcp\\') && (new ReflectionClass($name))->isInstantiable()) ? $this->build($name)
				: ($parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : $this->mock($name));
		}
		return new $class(...$args);
	}

	/** @return GrantPolicy grants of $this->grants for alice */
	private function policy(): GrantPolicy {
		if ($this->realPolicy !== null) {
			return $this->realPolicy;
		}
		$policy = $this->mock(GrantPolicy::class);
		$policy->method('granted')->willReturnCallback(fn (string $uid, string $module, string $operation): bool
			=> $module === 'collectives' && in_array($operation, $this->grants, true));
		return $policy;
	}

	/** @return IAppManager Collectives 4.7.1, enabled while $this->appEnabled */
	private function apps(): IAppManager {
		$apps = $this->mock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(fn (): bool => $this->appEnabled);
		$apps->method('getAppVersion')->willReturnCallback(fn (string $app): string => $app === 'circles' ? $this->teamsVersion : $this->version);
		return $apps;
	}

	/** @return IUserManager knows alice */
	private function users(): IUserManager {
		$users = $this->mock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => in_array($uid, self::ACCOUNTS, true) ? $this->user($uid) : null);
		return $users;
	}

	/** @return IUserSession the session of $this->sessionUser */
	private function session(): IUserSession {
		$session = $this->mock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): IUser => $this->user($this->sessionUser));
		return $session;
	}

	/** @param string $uid account @return IUser the account */
	private function user(string $uid): IUser {
		$user = $this->mock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn(ucfirst($uid));
		return $user;
	}

	/** @return IConfig the server secret and the user's Collectives folder setting */
	private function config(): IConfig {
		$config = $this->mock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('secret');
		$config->method('getUserValue')->willReturnCallback(fn (string $uid, string $app, string $key, mixed $default): string => match (true) {
			$app === 'collectives' && $key === 'user_folder' => $this->folderSetting === '' ? (string)$default : $this->folderSetting,
			$app === 'circles' && $key === 'userSingleId' && $uid === 'alice' => $this->teamsIdentity,
			$app === 'circles' && $key === 'userSingleId' => isset(TeamsScene::$identities[$uid]) ? 'single-' . $uid : '',
			default => (string)$default,
		});
		$config->expects(TestCase::never())->method('setUserValue');
		return $config;
	}

	/** @return GuestIdentity answers $this->guestAccount; null throws, as a failed identification is refused */
	private function guests(): GuestIdentity {
		$guests = $this->mock(GuestIdentity::class);
		$guests->method('isGuestAccount')->willReturnCallback(fn (): bool => $this->guestAccount ?? true);
		return $guests;
	}

	/** @return IGroupManager the groups staff (3 accounts) and design (2 accounts) */
	private function groups(): IGroupManager {
		$groups = $this->mock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(function (string $gid): ?IGroup {
			if (!in_array($gid, ['staff', 'design'], true)) {
				return null;
			}
			$group = $this->mock(IGroup::class);
			$group->method('count')->willReturn($gid === 'staff' ? 3 : 2);
			$group->method('getDisplayName')->willReturn(ucfirst($gid));
			return $group;
		});
		return $groups;
	}

	/** @return IDBConnection whose four-byte support is $this->fourBytes */
	private function database(): IDBConnection {
		$db = $this->mock(IDBConnection::class);
		$db->method('supports4ByteText')->willReturnCallback(fn (): bool => $this->fourBytes);
		return $db;
	}

	/** @return IFactory alice writes Portuguese */
	private function languages(): IFactory {
		$languages = $this->mock(IFactory::class);
		$languages->method('getUserLanguage')->willReturn('pt_BR');
		return $languages;
	}

	/** @return IRootFolder only asked whether paths of the collectives' storage exist */
	private function storage(): IRootFolder {
		$root = $this->mock(IRootFolder::class);
		$root->method('nodeExists')->willReturnCallback(static fn (string $path): bool => TeamsScene::exists($path));
		return $root;
	}

	/** @return IAppConfig no administrator default folder */
	private function appConfig(): IAppConfig {
		$config = $this->mock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		return $config;
	}

	/** @return VisibilityGuard hides $this->hidden */
	private function visibility(): VisibilityGuard {
		$guard = $this->mock(VisibilityGuard::class);
		$guard->method('isVisible')->willReturnCallback(fn (Node $node): bool => !in_array($node->getId(), $this->hidden, true));
		return $guard;
	}

	/** @return OutputReferenceFilter refuses $this->outOfScope */
	private function scope(): OutputReferenceFilter {
		$filter = $this->mock(OutputReferenceFilter::class);
		$filter->method('allowsUserNode')->willReturnCallback(fn (string $uid, Node $node): bool => !in_array($node->getId(), $this->outOfScope, true));
		return $filter;
	}

	/** @param class-string $class type to mock @return \PHPUnit\Framework\MockObject\MockObject mock owned by the test */
	private function mock(string $class): \PHPUnit\Framework\MockObject\MockObject {
		return (new \ReflectionMethod($this->test, 'createMock'))->invoke($this->test, $class);
	}
}
