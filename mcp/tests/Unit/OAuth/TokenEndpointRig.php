<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\Controller\OAuthController;
use OCA\Mcp\OAuth\AccessTokenAuthenticator;
use OCA\Mcp\OAuth\AuthorizationRequest;
use OCA\Mcp\OAuth\AuthorizationValidator;
use OCA\Mcp\OAuth\ClientMetadata;
use OCA\Mcp\OAuth\ClientResolver;
use OCA\Mcp\OAuth\ResourceUrl;
use OCA\Mcp\OAuth\TokenHasher;
use OCA\Mcp\OAuth\TokenOwnerGate;
use OCA\Mcp\OAuth\TokenService;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The real token endpoint of a self-hosted client for the issue #19 tests: OAuthController::token() over a real
 * TokenService, its in-memory store and a real authenticator, with one eligible user (alice) and two stored self-hosted
 * clients. Each test builds its own rig, so a replay that revokes credentials never reaches another test.
 */
final class TokenEndpointRig {
	public const REDIRECT = 'https://callback.example.test/oauth2_callback';
	public const OTHER_REDIRECT = 'https://other.example.test/cb';
	public const CLIENT = 'nextcloud-mcp-custom-aaaaaaaaaaaa';
	public const OTHER_CLIENT = 'nextcloud-mcp-custom-bbbbbbbbbbbb';
	public const RESOURCE = 'https://cloud.example.org/apps/mcp/';
	public const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
	public const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

	public readonly InMemoryOAuthStore $store;
	public readonly TokenService $service;
	public readonly AccessTokenAuthenticator $authenticator;

	/** @var array<string,mixed> parameters of the next token request */
	private array $params = [];
	/** Raw Authorization header of the next token request ('' for none). */
	private string $header = '';

	/**
	 * Builds the rig the way TokenFlowTest builds its service, plus the two self-hosted clients.
	 *
	 * @param TestCase $test the test that owns the doubles
	 * @return void
	 */
	public function __construct(private TestCase $test) {
		$mock = fn (string $class) => (new \ReflectionMethod($test, 'createMock'))->invoke($test, $class);
		$this->store = new InMemoryOAuthStore();
		$appConfig = (new InMemoryConfig())->mock($test);
		$policy = InMemoryConfig::policy($appConfig, $this->store);
		$policy->setGlobalEnabled(true);
		$policy->setEligible('alice', true);
		$policy->setConnected('alice', true);
		$config = $mock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('instance-secret');
		$hasher = new TokenHasher($config);
		$time = $mock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_000_000);
		$alice = $mock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$alice->method('isEnabled')->willReturn(true);
		$users = $mock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid) => $uid === 'alice' ? $alice : null);
		$writer = InMemoryConfig::writer($appConfig, $this->store);
		$gate = new TokenOwnerGate($users, $policy, $writer, $mock(IUserConfig::class), $mock(IAppConfig::class), new NullLogger(),
			InMemoryConfig::noCauses($test));
		$clients = new ClientsTable($test);
		$clients->custom([
			['id' => 'aaaaaaaaaaaa', 'name' => 'Synthetic', 'enabled' => true, 'redirectUris' => [self::REDIRECT]],
			['id' => 'bbbbbbbbbbbb', 'name' => 'Other', 'enabled' => true, 'redirectUris' => [self::OTHER_REDIRECT]],
		]);
		$this->service = new TokenService($this->store, $hasher, $time, $gate, $writer, InMemoryConfig::authUsers($appConfig),
			InMemoryConfig::processor($appConfig), $clients->mapper);
		$this->authenticator = new AccessTokenAuthenticator($this->store, $hasher, $time, $gate, InMemoryConfig::authUsers($appConfig),
			InMemoryConfig::state($appConfig), $clients->mapper);
	}

	/**
	 * A fresh authorization code for a client, issued by the consent path.
	 *
	 * @param string $clientId client the code is issued to
	 * @param string $redirect its redirect URI
	 * @return string the code
	 */
	public function code(string $clientId = self::CLIENT, string $redirect = self::REDIRECT): string {
		$request = new AuthorizationRequest(new ClientMetadata($clientId, 'Synthetic', [$redirect]), $redirect, 'xyz',
			self::CHALLENGE, self::RESOURCE, 'mcp');
		return (string)$this->service->consent($request, 'alice');
	}

	/**
	 * The parameters of an authorization_code request, without client_id.
	 *
	 * @param string $code the code
	 * @param string $verifier the PKCE verifier
	 * @return array<string,string> the parameters
	 */
	public static function exchange(string $code, string $verifier = self::VERIFIER): array {
		return ['grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => self::REDIRECT];
	}

	/**
	 * A Basic header with the given form-encoded user and password.
	 *
	 * @param string $user the user part
	 * @param string $password the password part
	 * @return string the raw header
	 */
	public static function basic(string $user, string $password = ''): string {
		return 'Basic ' . base64_encode($user . ':' . $password);
	}

	/**
	 * Sends one token request through OAuthController::token().
	 *
	 * @param array<string,mixed> $params the request parameters
	 * @param string $header the raw Authorization header, '' for none
	 * @return array{status:int, body:array<string,mixed>} the HTTP status and the JSON body
	 */
	public function token(array $params, string $header = ''): array {
		$this->params = $params;
		$this->header = $header;
		$response = $this->controller()->token();
		$this->test->assertInstanceOf(JSONResponse::class, $response);
		return ['status' => $response->getStatus(), 'body' => $response->getData()];
	}

	/**
	 * The controller of one request; only the request and the token service matter to token().
	 *
	 * @return OAuthController the controller
	 */
	private function controller(): OAuthController {
		$mock = fn (string $class) => (new \ReflectionMethod($this->test, 'createMock'))->invoke($this->test, $class);
		$request = $mock(IRequest::class);
		$request->method('getParams')->willReturnCallback(fn (): array => $this->params);
		$request->method('getHeader')->willReturnCallback(fn (string $name): string => strcasecmp($name, 'Authorization') === 0 ? $this->header : '');
		$resolver = $mock(ClientResolver::class);
		return new OAuthController('mcp', $request, $mock(IUserSession::class), $mock(ISession::class), $mock(IURLGenerator::class),
			new AuthorizationValidator($resolver), $this->service, $mock(GrantPolicy::class), $mock(ResourceUrl::class));
	}
}
