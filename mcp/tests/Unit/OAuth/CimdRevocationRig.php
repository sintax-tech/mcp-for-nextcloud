<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\OAuth\Authorization\{AuthorizationRequest, AuthorizationValidator};
use OCA\Mcp\OAuth\Clients\{ClientAdmission, ClientMetadata, ClientMetadataFetcher, ClientResolver, OAuthClientsService};
use OCA\Mcp\OAuth\Tokens\{AccessTokenAuthenticator, TokenOwnerGate, TokenService};
use OCA\Mcp\OAuth\Storage\TokenHasher;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\{IAppConfig, IConfig, IUser, IUserManager};
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Production admission and services over real host rows, with deterministic withdrawal during the external phase. */
final class CimdRevocationRig {
	public const CLIENT = 'https://claude.ai/client';
	public const KEEP = 'https://chatgpt.com/client';
	public const RESOURCE = TokenEndpointRig::RESOURCE;
	public InMemoryConfig $data;
	public ClientsTable $table;
	public InMemoryOAuthStore $store;
	public OAuthClientsService $settings;
	public TokenService $tokens;
	public AccessTokenAuthenticator $bearer;
	public AuthorizationValidator $validator;
	public ?\Closure $between = null;

	/** @param TestCase $test Test owning doubles. */
	public function __construct(TestCase $test) {
		$mock = fn (string $c) => (new \ReflectionMethod($test, 'createMock'))->invoke($test, $c);
		$this->data = new InMemoryConfig();
		$config = $this->data->mock($test);
		$this->store = new InMemoryOAuthStore();
		$this->table = new ClientsTable($test);
		$policy = InMemoryConfig::policy($config, $this->store);
		$policy->setGlobalEnabled(true);
		$policy->setEligible('alice', true);
		$policy->setConnected('alice', true);
		$writer = InMemoryConfig::writer($config, $this->store);
		$secret = $mock(IConfig::class);
		$secret->method('getSystemValueString')->willReturn('test-secret');
		$hasher = new TokenHasher($secret);
		$time = $mock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_000_000);
		$alice = $mock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$alice->method('isEnabled')->willReturn(true);
		$users = $mock(IUserManager::class);
		$users->method('get')->willReturn($alice);
		$hook = function (): void {
			$action = $this->between;
			$this->between = null;
			if ($action !== null) { $action(); }
		};
		$gate = new class($users, $policy, $writer, $mock(IUserConfig::class), $mock(IAppConfig::class), new NullLogger(), InMemoryConfig::noCauses($test), $hook) extends TokenOwnerGate {
			/** @param mixed ...$args Production dependencies and a one-shot hook. */
			public function __construct(...$args) {
				$this->hook = array_pop($args);
				parent::__construct(...$args);
			}
			private \Closure $hook;
			/** @param string $uid Owner. @param bool $connection Require connected. @return array|null Admission. */
			public function admission(string $uid, bool $connection): ?array {
				$result = parent::admission($uid, $connection);
				($this->hook)();
				return $result;
			}
		};
		$this->settings = new OAuthClientsService($writer, $config, $this->table->mapper, $this->store);
		$this->tokens = new TokenService($this->store, $hasher, $time, $gate, $writer,
			InMemoryConfig::authUsers($config), InMemoryConfig::processor($config), $this->table->mapper);
		$this->bearer = new AccessTokenAuthenticator($this->store, $hasher, $time, $gate,
			InMemoryConfig::authUsers($config), InMemoryConfig::state($config), $this->table->mapper);
		$this->validator = new AuthorizationValidator(new ClientResolver($mock(ClientMetadataFetcher::class), $this->table->mapper));
	}

	/** @param list<string> $hosts New approved hosts. @return void Official settings path. */
	public function hosts(array $hosts): void {
		$this->settings->update((string)$this->data->revision, $hosts, null, null);
	}

	/** @param string $client Identifier. @return AuthorizationRequest The generation a consent page carries. */
	public function request(string $client = self::CLIENT): AuthorizationRequest {
		$gen = ClientAdmission::generation($this->table->mapper, $client) ?? '0';
		return new AuthorizationRequest(new ClientMetadata($client, 'Test', ['https://callback.example.test/cb'], $gen),
			'https://callback.example.test/cb', 'state', TokenEndpointRig::CHALLENGE, self::RESOURCE, 'mcp');
	}

	/** @param string $code Code. @param string $client Client. @return array Token response. */
	public function exchange(string $code, string $client = self::CLIENT): array {
		return $this->tokens->exchangeCode(['code' => $code, 'client_id' => $client, 'redirect_uri' => 'https://callback.example.test/cb',
			'code_verifier' => TokenEndpointRig::VERIFIER, 'resource' => self::RESOURCE]);
	}

	/** @param string $client Client. @return array Tokens after real consent and exchange. */
	public function issue(string $client = self::CLIENT): array {
		return $this->exchange($this->tokens->consent($this->request($client), 'alice'), $client);
	}
}
