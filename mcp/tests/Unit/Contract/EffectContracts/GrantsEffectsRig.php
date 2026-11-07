<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Controller\GrantsController;
use OCA\Mcp\Controller\OverridePreviewController;
use OCA\Mcp\Access\Grants\OverridePreview;
use OCA\Mcp\Access\Policy\GroupGrantsService;
use OCA\Mcp\Access\Policy\PolicyWriter;
use OCA\Mcp\Tests\Unit\Access\Grants\MatrixFixture;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The administrator's permission API as the container wires it, for the controller effect contracts: the real
 * {@see GrantsController} and {@see OverridePreviewController} over the admin matrix fixture (real GrantPolicy,
 * PolicyWriter, GrantMatrix, logs gate and OAuth client settings) and the real {@see ToolRegistry} over
 * {@see GrantProbeModule}, which shows what each user is offered once a change lands. The service is on; ana and bob
 * are eligible, connected and hold one OAuth grant each; root is the administrator.
 */
final class GrantsEffectsRig {
	/** The users of the fixture. */
	public const USERS = ['ana', 'bob'];

	public readonly MatrixFixture $fx;
	/** @var array<string, mixed> JSON body of the next request */
	private array $body = [];
	/** @var array<string, string> placeholder of each policy command registered so far */
	private array $commands = [];

	/** @param TestCase $test the running test, owner of the doubles */
	public function __construct(private TestCase $test) {
		$this->fx = new MatrixFixture($test);
		$this->fx->addUser('ana', 'Ana');
		$this->fx->addUser('bob', 'Bob');
		$this->fx->policy->setGlobalEnabled(true);
		foreach (self::USERS as $uid) {
			$this->fx->policy->setEligible($uid, true);
			$this->fx->policy->setConnected($uid, true);
			$this->fx->oauth->insertToken(['user_id' => $uid, 'client_id' => 'https://claude.ai/c'], 0);
		}
		$this->fx->policy->setGrant('ana', 'notes', 'edit', true);
	}

	/** @return string the policy revision stored now */
	public function revision(): string {
		return (string)$this->fx->config->revision;
	}

	/**
	 * The controller of one request. The body carries the current revision unless it names expectedRevision itself
	 * (null leaves it out, as an outdated page would).
	 *
	 * @param array<string, mixed> $body JSON body of the request
	 * @param string $admin signed-in administrator, '' for nobody
	 * @return GrantsController the controller
	 */
	public function grants(array $body = [], string $admin = 'root'): GrantsController {
		$this->body = self::withRevision($body, $this->revision());
		return new GrantsController('mcp', $this->request(), $this->fx->matrix(), $this->fx->policy, $this->fx->userManager(),
			$this->fx->config->mock($this->test), $this->fx->logsAccess(), $this->fx->writer(), $this->fx->state(), $this->signedIn($admin),
			$this->mock(GroupGrantsService::class), $this->fx->clients());
	}

	/**
	 * @param array<string, mixed> $body JSON body of the request
	 * @param string $user signed-in user; only root is an administrator
	 * @return OverridePreviewController the preview of a reset, for one request
	 */
	public function preview(array $body, string $user = 'root'): OverridePreviewController {
		$this->body = $body;
		$groups = $this->mock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'root');
		$preview = new OverridePreview(InMemoryConfig::resolver($this->fx->policyConfig), $this->fx->userManager(),
			InMemoryConfig::groupDatabase($this->fx->policyConfig)->connection(), $this->fx->state());
		return new OverridePreviewController('mcp', $this->request(), $preview, $this->signedIn($user), $groups);
	}

	/**
	 * Sends one request to GrantsController and records it.
	 *
	 * @param ControllerTranscript $transcript the transcript of the flow
	 * @param string $step what the step proves
	 * @param string $route route name
	 * @param array<string, mixed> $body JSON body; the current revision is added unless it names one (null: none)
	 * @param \Closure(GrantsController): \OCP\AppFramework\Http\Response $call the controller method
	 * @param bool $unusable whether the policy writer is unusable during the request
	 * @return array<string, mixed> the recorded answer
	 */
	public function send(ControllerTranscript $transcript, string $step, string $route, array $body, \Closure $call, bool $unusable = false): array {
		$sent = self::withRevision($body, $this->revision());
		$controller = $this->grants($body);
		$response = $unusable ? UnusableWriter::during($this->writer(), static fn () => $call($controller)) : $call($controller);
		return $transcript->record($step, ['route' => $route, 'user' => 'root', 'body' => $sent], $response);
	}

	/** @return PolicyWriter the writer behind every route */
	public function writer(): PolicyWriter {
		return $this->fx->writer();
	}

	/**
	 * @param string $uid a user
	 * @return list<string> the probe tools the registry offers the user now, in list order
	 */
	public function tools(string $uid): array {
		$apps = $this->mock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$registry = new ToolRegistry([new GrantProbeModule()], $this->fx->policy, $apps, $this->fx->userManager(), new NullLogger());
		return array_column($this->fx->policy->within($uid, static fn (): array => $registry->definitions($uid)), 'name');
	}

	/**
	 * Registers the policy commands stored so far: each event id is `<command>-<n>`, the command 128 random bits.
	 *
	 * @param ControllerTranscript $transcript the transcript of the flow
	 * @return void
	 */
	public function register(ControllerTranscript $transcript): void {
		foreach (array_keys($this->fx->config->events) as $id) {
			$this->test->assertMatchesRegularExpression('/^[0-9a-f]{32}-[1-9][0-9]*$/', (string)$id);
			$command = explode('-', (string)$id)[0];
			$this->commands[$command] ??= '<command#' . (count($this->commands) + 1) . '>';
			$transcript->literal($command, $this->commands[$command]);
		}
	}

	/**
	 * @return array<string, mixed> the stored policy, its events, the OAuth grants and, per user, what the MCP endpoint
	 *     decides: whether the user may connect at all (McpController's gate) and which probe tools tools/list offers
	 */
	public function state(): array {
		return [
			'app' => $this->fx->config->app['mcp'] ?? [],
			'users' => array_map(static fn (array $apps): array => $apps['mcp'] ?? [], $this->fx->config->user),
			'revision' => $this->fx->config->revision,
			'authEpoch' => $this->fx->config->authEpoch,
			'events' => array_values(array_map(static fn (array $event): array => array_diff_key($event, ['createdAt' => 0]), $this->fx->config->events)),
			'oauthGrants' => array_values(array_column($this->fx->oauth->tokens, 'user_id')),
			'endpoint' => array_combine(self::USERS, array_map(fn (string $uid): array => ['canConnect' => $this->fx->policy->canConnect($uid),
				'tools' => $this->tools($uid)], self::USERS)),
		];
	}

	/**
	 * @param array<string, mixed> $body JSON body
	 * @param string $revision the current revision
	 * @return array<string, mixed> the body with expectedRevision unless it names one; a null one is left out
	 */
	public static function withRevision(array $body, string $revision): array {
		$body += ['expectedRevision' => $revision];
		return array_filter($body, static fn (mixed $value, string $key): bool => $key !== 'expectedRevision' || $value !== null, ARRAY_FILTER_USE_BOTH);
	}

	/** @return IRequest a request whose JSON body is the one of the next call */
	private function request(): IRequest {
		$request = $this->mock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => array_key_exists($key, $this->body)
			? $this->body[$key] : $default);
		return $request;
	}

	/** @return IUserSession the session of $uid, or of nobody for '' */
	private function signedIn(string $uid): IUserSession {
		$user = $this->mock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->mock(IUserSession::class);
		$session->method('getUser')->willReturn($uid === '' ? null : $user);
		return $session;
	}

	/**
	 * @param class-string $class type to mock
	 * @return \PHPUnit\Framework\MockObject\MockObject mock owned by the running test
	 */
	private function mock(string $class): \PHPUnit\Framework\MockObject\MockObject {
		return (new \ReflectionMethod($this->test, 'createMock'))->invoke($this->test, $class);
	}
}
