<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Collectives;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Visibility\OutputReferenceFilter;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Collectives\CollectivesModule;
use OCA\Mcp\Modules\Collectives\Integration\CollectivesAdmission;
use OCA\Mcp\Modules\Collectives\Integration\CollectivesGateway;
use OCA\Mcp\Modules\Collectives\Integration\CollectivesSurface;
use OCA\Mcp\Modules\Collectives\Integration\PageFiles;
use OCP\App\IAppManager;
use OCP\Files\Node;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
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
	public array $grants = ['read', 'create', 'edit', 'move'];
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
	/** @var array<class-string, object> instances already built */
	private array $built = [];

	/** @param TestCase $test owner of the mocks */
	public function __construct(private TestCase $test) {
		CollectivesScene::reset($test);
	}

	/** @return CollectivesModule the module, its tools resolved from the same wiring */
	public function module(): CollectivesModule {
		return new CollectivesModule($this->container());
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
			'OCA\\Collectives\\Service\\SearchService' => new $class(),
			ContainerInterface::class => $this->container(),
			PlanState::class => new PlanState($this->config()),
			IAppManager::class => $this->apps(),
			CollectivesAdmission::class => new CollectivesAdmission($this->policy(), $this->apps(), $this->users(), $this->session(),
				$this->config(), $this->appConfig(), $this->surface ?? new CollectivesSurface()),
			CollectivesGateway::class => new CollectivesGateway($this->container(), $this->build(CollectivesAdmission::class)),
			CollectivesSurface::class => $this->surface ?? new CollectivesSurface(),
			PageFiles::class => new PageFiles($this->visibility(), $this->scope()),
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
			$args[] = $name === IAppManager::class || (str_starts_with($name, 'OCA\\Mcp\\') && (new ReflectionClass($name))->isInstantiable()) ? $this->build($name)
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
		$apps->method('getAppVersion')->willReturnCallback(fn (): string => $this->version);
		return $apps;
	}

	/** @return IUserManager knows alice */
	private function users(): IUserManager {
		$users = $this->mock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => $uid === 'alice' ? $this->user('alice') : null);
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
		return $user;
	}

	/** @return IConfig the server secret and the user's Collectives folder setting */
	private function config(): IConfig {
		$config = $this->mock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('secret');
		$config->method('getUserValue')->willReturnCallback(fn (string $uid, string $app, string $key, mixed $default): string
			=> $app === 'collectives' && $key === 'user_folder' ? ($this->folderSetting === '' ? (string)$default : $this->folderSetting) : (string)$default);
		$config->expects(TestCase::never())->method('setUserValue');
		return $config;
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
