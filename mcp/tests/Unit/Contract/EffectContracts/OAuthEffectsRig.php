<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Controller\ConnectionsController;
use OCA\Mcp\Controller\MyConnectionsController;
use OCA\Mcp\Controller\OAuthController;
use OCA\Mcp\Controller\SettingsController;
use OCA\Mcp\OAuth\AccessTokenAuthenticator;
use OCA\Mcp\OAuth\AuthorizationValidator;
use OCA\Mcp\OAuth\ClientMetadataFetcher;
use OCA\Mcp\OAuth\ClientResolver;
use OCA\Mcp\OAuth\ResourceUrl;
use OCA\Mcp\OAuth\TokenHasher;
use OCA\Mcp\OAuth\TokenOwnerGate;
use OCA\Mcp\OAuth\TokenService;
use OCA\Mcp\Service\ConnectionList;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\PolicyWriter;
use OCA\Mcp\Tests\Unit\Checkout\InMemoryCheckoutTokenStore;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\ClientsTable;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCA\Mcp\Tests\Unit\Tools\Files\InMemoryBatchStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The OAuth side of an instance as the container wires it, for the controller effect contracts: the real
 * {@see GrantPolicy}, {@see PolicyWriter}, {@see TokenService}, {@see AccessTokenAuthenticator} and
 * {@see ConnectionList} over the in-memory OAuth store, one self-hosted client in its real table, and the stores the
 * personal switch empties. alice is eligible and bob is not; the service is on. Only the request, the session, the
 * clock and the URL generator are doubles, as the framework provides them.
 */
final class OAuthEffectsRig {
	/** Self-hosted client every authorization uses. */
	public const CLIENT = 'nextcloud-mcp-custom-aaaaaaaaaaaa';
	/** Its one registered redirect URI. */
	public const REDIRECT = 'https://callback.example.test/cb';
	/** Origin of the instance. */
	public const ISSUER = 'https://cloud.example.org';
	/** The MCP resource of the instance. */
	public const RESOURCE = 'https://cloud.example.org/apps/mcp/';
	/** PKCE verifier of every authorization (RFC 7636 appendix B). */
	public const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
	/** Its S256 challenge. */
	public const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
	/** Shape of every secret TokenHasher draws. */
	public const SECRET = '/^ncmcp_(at|rt|code)_[A-Za-z0-9_-]{43}$/';

	public readonly InMemoryConfig $data;
	public readonly InMemoryOAuthStore $store;
	public readonly GrantPolicy $policy;
	public readonly PolicyWriter $writer;
	public readonly TokenService $tokens;
	public readonly TokenHasher $hasher;
	public readonly AccessTokenAuthenticator $authenticator;
	public readonly ConnectionList $connections;
	public readonly InMemoryCheckoutTokenStore $checkout;
	public readonly InMemoryBatchStore $batches;
	/** @var array<string, bool> account state, independently of MCP eligibility */
	public array $enabled = [];
	private ClientsTable $clients;
	private IUserManager $users;
	/** @var array<string, mixed> the PHP session of the signed-in user */
	private array $session = [];
	/** @var array<string, mixed> parameters of the next request */
	private array $params = [];
	/** Number of secrets registered so far, for their placeholders. */
	private int $secrets = 0;

	/** @param TestCase $test the running test, owner of the doubles */
	public function __construct(private TestCase $test) {
		$this->data = new InMemoryConfig();
		$this->data->userExists = static fn (string $uid): bool => in_array($uid, ['alice', 'bob'], true);
		$config = $this->data->mock($test);
		$this->store = new InMemoryOAuthStore();
		$this->policy = InMemoryConfig::policy($config, $this->store);
		$this->writer = InMemoryConfig::writer($config, $this->store);
		$this->policy->setGlobalEnabled(true);
		$this->policy->setEligible('alice', true);
		$system = $this->mock(IConfig::class);
		$system->method('getSystemValueString')->willReturn('instance-secret');
		$this->hasher = new TokenHasher($system);
		$time = $this->mock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->data->now);
		$this->users = $this->mock(IUserManager::class);
		$this->users->method('get')->willReturnCallback(fn (string $uid): ?IUser => ($this->data->userExists)($uid) ? $this->user($uid) : null);
		$gate = new TokenOwnerGate($this->users, $this->policy, $this->writer, $this->mock(IUserConfig::class), $this->mock(IAppConfig::class),
			new NullLogger(), InMemoryConfig::noCauses($test));
		$this->clients = new ClientsTable($test);
		$this->clients->custom([['id' => 'aaaaaaaaaaaa', 'name' => 'Synthetic', 'enabled' => true, 'redirectUris' => [self::REDIRECT]]]);
		$this->tokens = new TokenService($this->store, $this->hasher, $time, $gate, $this->writer, InMemoryConfig::authUsers($config),
			InMemoryConfig::processor($config), $this->clients->mapper);
		$this->authenticator = new AccessTokenAuthenticator($this->store, $this->hasher, $time, $gate, InMemoryConfig::authUsers($config),
			InMemoryConfig::state($config), $this->clients->mapper);
		$this->connections = new ConnectionList($this->store, $this->tokens, $this->users, $time, $this->clients->mapper);
		$this->checkout = new InMemoryCheckoutTokenStore();
		$this->batches = new InMemoryBatchStore($this->mock(IDBConnection::class));
	}

	/**
	 * @param array<string, mixed> $params parameters of the request
	 * @return OAuthController the OAuth endpoints of one request signed in as $uid
	 */
	public function oauth(string $uid, array $params = []): OAuthController {
		$this->params = $params;
		$session = $this->mock(ISession::class);
		$session->method('get')->willReturnCallback(fn (string $key): mixed => $this->session[$key] ?? null);
		$session->method('set')->willReturnCallback(function (string $key, mixed $value): void {
			$this->session[$key] = $value;
		});
		$session->method('remove')->willReturnCallback(function (string $key): void {
			unset($this->session[$key]);
		});
		$urls = $this->mock(ResourceUrl::class);
		$urls->method('base')->willReturn(self::RESOURCE);
		$urls->method('issuer')->willReturn(self::ISSUER);
		$resolver = new ClientResolver($this->mock(ClientMetadataFetcher::class), $this->clients->mapper);
		return new OAuthController('mcp', $this->request(), $this->signedIn($uid), $session, $this->urls(),
			new AuthorizationValidator($resolver), $this->tokens, $this->policy, $urls);
	}

	/** @return MyConnectionsController the personal connection list of one request signed in as $uid */
	public function mine(string $uid): MyConnectionsController {
		return new MyConnectionsController('mcp', $this->request(), $this->connections, $this->signedIn($uid));
	}

	/** @return ConnectionsController the administrator's connection list of one request */
	public function admin(): ConnectionsController {
		return new ConnectionsController('mcp', $this->request(), $this->connections);
	}

	/** @return SettingsController the personal settings form target of one request signed in as $uid */
	public function settings(string $uid): SettingsController {
		return new SettingsController('mcp', $this->request(), $this->urls(), $this->signedIn($uid), $this->policy, $this->checkout, $this->batches);
	}

	/**
	 * @param array<string, string> $extra parameters added to or replacing the valid ones
	 * @return array<string, string> the query of a valid authorization request of the self-hosted client
	 */
	public static function authorization(array $extra = []): array {
		return $extra + ['client_id' => self::CLIENT, 'redirect_uri' => self::REDIRECT, 'response_type' => 'code',
			'code_challenge_method' => 'S256', 'code_challenge' => self::CHALLENGE, 'state' => 'st-1', 'scope' => 'mcp'];
	}

	/**
	 * @param string $code the authorization code
	 * @param string $verifier the PKCE verifier
	 * @return array<string, string> the parameters of its exchange at the token endpoint
	 */
	public static function exchange(string $code, string $verifier = self::VERIFIER): array {
		return ['grant_type' => 'authorization_code', 'client_id' => self::CLIENT, 'code' => $code, 'code_verifier' => $verifier,
			'redirect_uri' => self::REDIRECT];
	}

	/**
	 * A whole connection through the real endpoints: consent page, allow, code exchange.
	 *
	 * @param string $uid the consenting user, who must be eligible
	 * @return array{access_token:string, refresh_token:string} the issued tokens
	 */
	public function connect(string $uid): array {
		$this->oauth($uid, self::authorization())->authorize();
		$target = $this->oauth($uid)->consent((string)$this->pending(), 'allow')->getParams()['target'];
		$code = (string)ControllerTranscript::url($target)['query']['code'];
		$body = $this->oauth('', self::exchange($code))->token()->getData();
		$this->test->assertSame($uid, $this->bearer($body['access_token']));
		return $body;
	}

	/** @return string|null id of the authorization request pending in the session, if any */
	public function pending(): ?string {
		$pending = $this->session['mcp_oauth_pending'] ?? null;
		return is_array($pending) ? (string)array_key_first($pending) : null;
	}

	/**
	 * Registers a secret the code drew: asserts its shape, then hides it and its stored HMAC in the transcript.
	 *
	 * @param ControllerTranscript $transcript the transcript of the flow
	 * @param string $secret a code, access or refresh token, as answered
	 * @return string the secret, for the next request
	 */
	public function secret(ControllerTranscript $transcript, string $secret): string {
		$this->test->assertMatchesRegularExpression(self::SECRET, $secret);
		$label = explode('_', $secret)[1] . '#' . ++$this->secrets;
		$transcript->literal($secret, '<' . $label . '>');
		$transcript->literal($this->hasher->hash($secret), '<hash of ' . $label . '>');
		return $secret;
	}

	/**
	 * Registers the pending request id the consent page shows (128 random bits in hex).
	 *
	 * @param ControllerTranscript $transcript the transcript of the flow
	 * @return string the id
	 */
	public function pendingId(ControllerTranscript $transcript): string {
		$pending = (string)$this->pending();
		$this->test->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $pending);
		$transcript->literal($pending, '<pending#' . ++$this->secrets . '>');
		return $pending;
	}

	/**
	 * @param string $accessToken a bearer token
	 * @return string|null the uid the MCP endpoint would authenticate it as, null when it is refused
	 */
	public function bearer(string $accessToken): ?string {
		return $this->authenticator->authenticate('Bearer ' . $accessToken, self::RESOURCE)?->getUID();
	}

	/** @return array<string, mixed> what the stores hold now, JSON-encodable */
	public function state(): array {
		$rows = static fn (array $rows, array $keys): array => array_values(array_map(
			static fn (array $row): array => array_intersect_key($row, array_flip($keys)), $rows));
		return [
			// The pending id is the key of the stored request: shown as a value, so the transcript can hide it.
			'session' => array_map(static fn (mixed $value): mixed => is_array($value)
				? array_map(null, array_keys($value), array_values($value)) : $value, $this->session),
			'app' => $this->data->app['mcp'] ?? [],
			'users' => array_map(static fn (array $apps): array => $apps['mcp'] ?? [], $this->data->user),
			'policy' => ['revision' => $this->data->revision, 'authEpoch' => $this->data->authEpoch, 'generations' => $this->data->generations],
			'codes' => array_values($this->store->codes),
			'tokens' => array_values($this->store->tokens),
			'spent' => array_values($this->store->spent),
			'checkout' => $rows($this->checkout->rows, ['id', 'user_id', 'kind']),
			'batches' => $rows($this->batches->rows, ['id', 'user_id']),
		];
	}

	/** @return IRequest a request whose parameters are the ones of the next call */
	private function request(): IRequest {
		$request = $this->mock(IRequest::class);
		$request->method('getParams')->willReturnCallback(fn (): array => $this->params);
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => $this->params[$key] ?? $default);
		$request->method('getHeader')->willReturn('');
		return $request;
	}

	/** @return IURLGenerator links to routes as `/route/<name>?<params>` */
	private function urls(): IURLGenerator {
		$urls = $this->mock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(static fn (string $route, array $params = []): string => '/route/' . $route
			. ($params === [] ? '' : '?' . http_build_query($params)));
		return $urls;
	}

	/** @return IUserSession the session of $uid, or of nobody for '' */
	private function signedIn(string $uid): IUserSession {
		$session = $this->mock(IUserSession::class);
		$session->method('getUser')->willReturn($uid === '' ? null : $this->user($uid));
		return $session;
	}

	/** @return IUser an enabled account */
	private function user(string $uid): IUser {
		$user = $this->mock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn(ucfirst($uid));
		$user->method('isEnabled')->willReturnCallback(fn (): bool => $this->enabled[$uid] ?? true);
		return $user;
	}

	/**
	 * @param class-string $class type to mock
	 * @return \PHPUnit\Framework\MockObject\MockObject mock owned by the running test
	 */
	private function mock(string $class): \PHPUnit\Framework\MockObject\MockObject {
		return (new \ReflectionMethod($this->test, 'createMock'))->invoke($this->test, $class);
	}
}
