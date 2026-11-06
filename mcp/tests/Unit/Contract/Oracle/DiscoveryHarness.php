<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\Oracle;

use OCA\Mcp\AppInfo\Application;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Grants\GrantCatalog;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Mcp\Protocol\McpProtocol;
use OCA\Mcp\Mcp\Prompts\PromptCatalog;
use OCA\Mcp\Mcp\Resources\ResourceRegistry;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Tests\Unit\ArrayGroupBackend;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\InstalledApp;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use OCA\Mcp\Modules\Files\TextExtractor;
use OCA\Mcp\Mcp\Resources\NoteResource;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The MCP endpoint as Application wires it (ToolRegistry over Application::MODULES, PromptCatalog, ResourceRegistry,
 * GuestRestrictions), for the account `alice` under one {@see DiscoveryScenario}. Requests go through
 * {@see McpProtocol::handle()} and come back as the JSON the endpoint sends, decoded with objects kept as objects so
 * `{}` and `[]` survive into the golden.
 */
final class DiscoveryHarness {
	/** The account every discovery request is made for. */
	public const USER = 'alice';

	private McpProtocol $protocol;
	private GrantPolicy $policy;

	/**
	 * @param TestCase $test the running test, owner of the mocks
	 * @param DiscoveryScenario $scenario the state discovery is observed under
	 */
	public function __construct(private TestCase $test, DiscoveryScenario $scenario) {
		$data = new InMemoryConfig();
		$config = $data->mock($test);
		$policy = InMemoryConfig::policy($config, new InMemoryOAuthStore());
		$this->policy = $policy;
		if ($scenario->unknownGrants !== []) {
			$data->groupBackends = [new ArrayGroupBackend(static fn (): array => [DiscoveryScenario::PAUSED_GROUP => [self::USER]])];
			InMemoryConfig::groupPolicy($config, DiscoveryScenario::PAUSED_GROUP, $scenario->unknownGrants, 'needs_confirm');
		}
		foreach (GrantCatalog::CATALOG as $module => $operations) {
			foreach ($operations as $operation) {
				$grant = $scenario->grant($module, $operation);
				if ($grant !== null) {
					$policy->setGrant(self::USER, $module, $operation, $grant);
				}
			}
		}
		$apps = $this->mock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn($scenario->optionalApps);
		$users = $this->mock(IUserManager::class);
		$users->method('get')->willReturn($this->mock(IUser::class));
		$guests = null;
		if ($scenario->guestApps !== null) {
			$allowed = $scenario->guestApps;
			$guests = $this->mock(GuestRestrictions::class);
			$guests->method('allowsApp')->willReturnCallback(static fn (string $uid, string $app): bool => $app === '' || in_array($app, $allowed, true));
		}
		$logger = $this->mock(LoggerInterface::class);
		$modules = (new ModuleBuilder($test, $scenario->deckDependencies))->modules(Application::MODULES);
		$registry = new ToolRegistry($modules, $policy, $apps, $users, $logger, $guests, capabilities: new \OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry([(new ModuleBuilder($test, $scenario->deckDependencies))->build(\OCA\Mcp\Modules\Deck\Integration\DeckCapabilities::class)], $logger));
		$resources = new ResourceRegistry($registry, $policy, $apps, $users, $this->mock(IRootFolder::class), $this->mock(VisibilityGuard::class),
			new \OCA\Mcp\Modules\Files\ExtractedFileText($this->mock(TextExtractor::class)), $this->mock(NoteResource::class), $registry->guide(), $this->mock(FilesAccessGuard::class), $guests);
		$installed = $this->mock(IAppManager::class);
		InstalledApp::mock($installed);
		$this->protocol = new McpProtocol($registry, new PromptCatalog(), $policy, $logger, $installed, $resources);
	}

	/**
	 * @param string $method JSON-RPC method of the legacy era
	 * @param array<string, mixed>|null $params request params; null sends none
	 * @return array{status:int, body:mixed} HTTP status and response body, decoded with JSON objects as stdClass
	 */
	public function request(string $method, ?array $params = null): array {
		$message = ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method] + ($params === null ? [] : ['params' => $params]);
		$version = $method === 'initialize' ? '' : McpProtocol::VERSION;
		// One policy operation per request, as McpController opens it around the endpoint.
		$out = $this->policy->within(self::USER, fn (): array => $this->protocol->handle(json_encode($message, JSON_THROW_ON_ERROR),
			$version, self::USER));
		$body = json_encode($out['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		return ['status' => $out['status'], 'body' => json_decode($body, false, 512, JSON_THROW_ON_ERROR)];
	}

	/** @return array{status:int, body:mixed} initialize of the legacy era, as claude.ai sends it */
	public function initialize(): array {
		return $this->request('initialize', ['protocolVersion' => McpProtocol::VERSION, 'capabilities' => new \stdClass(),
			'clientInfo' => ['name' => 'Claude-User', 'version' => '1.0']]);
	}

	/** @return array{status:int, body:mixed} the mcp_guide tool called without arguments: the whole guide of this account */
	public function guide(): array {
		return $this->request('tools/call', ['name' => 'mcp_guide', 'arguments' => new \stdClass()]);
	}

	/**
	 * @param class-string $class type to mock
	 * @return \PHPUnit\Framework\MockObject\MockObject mock owned by the running test
	 */
	private function mock(string $class): \PHPUnit\Framework\MockObject\MockObject {
		return (new ReflectionMethod($this->test, 'createMock'))->invoke($this->test, $class);
	}
}
