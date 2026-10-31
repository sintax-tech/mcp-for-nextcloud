<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\Oracle;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\GuestRestrictions;
use OCA\Mcp\Mcp\Protocol\McpProtocol;
use OCA\Mcp\Mcp\Prompts\PromptCatalog;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\InstalledApp;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCA\Mcp\Mcp\Tools\ToolModule;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The tools/call path of the endpoint for effect contracts: the real {@see GrantPolicy} (alice holds every grant of
 * the catalog until a step revokes one), the real {@see ToolRegistry} and {@see McpProtocol}, a Guests double whose
 * allowed apps a step can narrow. The modules under test are built by the contract with their own stateful fakes and
 * handed over through {@see self::serve()} as a factory; modules that read the policy themselves take it from {@see self::policy()}.
 */
final class EffectsGate {
	/** The account every call is made for. */
	public const USER = 'alice';

	private InMemoryConfig $data;
	private IConfig $config;
	private GrantPolicy $policy;
	/** @var list<string>|null apps Guests allows alice; null: alice is not a guest */
	private ?array $guestApps = null;
	/** @var (\Closure(): list<ToolModule>)|null builds the modules of one request */
	private ?\Closure $modules = null;
	/** @var list<array{message:string, context:array<string, mixed>}> errors the endpoint logged, for failure messages */
	private array $logged = [];

	/** @param TestCase $test the running test, owner of the mocks */
	public function __construct(private TestCase $test) {
		$this->data = new InMemoryConfig();
		$this->config = $this->data->mock($test);
		$this->policy = InMemoryConfig::policy($this->config, new InMemoryOAuthStore());
		foreach (GrantPolicy::CATALOG as $module => $operations) {
			foreach ($operations as $operation) {
				$this->policy->setGrant(self::USER, $module, $operation, true);
			}
		}
	}

	/** @return GrantPolicy the policy of the gate, for modules that re-check a grant inside a tool */
	public function policy(): GrantPolicy {
		return $this->policy;
	}

	/** @return InMemoryConfig the configuration data behind the policy, shared with fakes that read IConfig */
	public function data(): InMemoryConfig {
		return $this->data;
	}

	/** @return IConfig the mock over {@see self::data()} */
	public function config(): IConfig {
		return $this->config;
	}

	/**
	 * Every call is one request: the modules are built again for it, as the container does per PHP request, so a
	 * per-request cache (the visibility of a node, a policy snapshot) never outlives it. The fakes behind them persist.
	 *
	 * @param \Closure(): list<ToolModule> $modules builds the modules under test, in tools/list order
	 * @return void
	 */
	public function serve(\Closure $modules): void {
		$this->modules = $modules;
	}

	/** @return McpProtocol the endpoint of one request over freshly built modules */
	private function protocol(): McpProtocol {
		if ($this->modules === null) {
			throw new \LogicException('serve() the modules first');
		}
		$apps = $this->mock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$users = $this->mock(IUserManager::class);
		$user = $this->mock(IUser::class);
		$user->method('getUID')->willReturn(self::USER);
		$users->method('get')->willReturn($user);
		$guests = $this->mock(GuestRestrictions::class);
		$guests->method('allowsApp')->willReturnCallback(fn (string $uid, string $app): bool => $app === '' || $this->guestApps === null
			|| in_array($app, $this->guestApps, true));
		$logger = $this->mock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (string|\Stringable $message, array $context = []): void {
			$this->logged[] = ['message' => (string)$message, 'context' => $context];
		});
		$registry = new ToolRegistry(($this->modules)(), $this->policy, $apps, $users, $logger, $guests);
		$installed = $this->mock(IAppManager::class);
		InstalledApp::mock($installed);
		return new McpProtocol($registry, new PromptCatalog(), $this->policy, $logger, $installed);
	}

	/**
	 * @param string $tool tool name
	 * @param array<string, mixed> $arguments tools/call arguments
	 * @return array{status:int, body:mixed} HTTP status and JSON-RPC body as the endpoint sends them, objects kept
	 */
	public function call(string $tool, array $arguments): array {
		$message = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
			'params' => ['name' => $tool, 'arguments' => $arguments === [] ? new \stdClass() : $arguments]];
		$protocol = $this->protocol();
		// One policy operation per request, as McpController opens it around the endpoint.
		$out = $this->policy->within(self::USER, static fn (): array => $protocol->handle(json_encode($message, JSON_THROW_ON_ERROR),
			McpProtocol::VERSION, self::USER));
		$body = json_encode($out['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		return ['status' => $out['status'], 'body' => json_decode($body, false, 512, JSON_THROW_ON_ERROR)];
	}

	/** @return list<array{message:string, context:array<string, mixed>}> errors logged so far (class and code only) */
	public function logged(): array {
		return $this->logged;
	}

	/**
	 * @param string $module catalog module
	 * @param string $operation catalog operation
	 * @param bool $allowed the individual grant of alice from now on
	 * @return void
	 */
	public function grant(string $module, string $operation, bool $allowed): void {
		$this->policy->setGrant(self::USER, $module, $operation, $allowed);
	}

	/**
	 * @param list<string>|null $apps apps Guests allows alice from now on; null makes her a regular account again
	 * @return void
	 */
	public function guest(?array $apps): void {
		$this->guestApps = $apps;
	}

	/**
	 * @param class-string $class type to mock
	 * @return \PHPUnit\Framework\MockObject\MockObject mock owned by the running test
	 */
	private function mock(string $class): \PHPUnit\Framework\MockObject\MockObject {
		return (new ReflectionMethod($this->test, 'createMock'))->invoke($this->test, $class);
	}
}
