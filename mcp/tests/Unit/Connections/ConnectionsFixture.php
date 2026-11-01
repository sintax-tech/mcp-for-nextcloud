<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Connections;

use OCA\Mcp\OAuth\Storage\TokenHasher;
use OCA\Mcp\OAuth\TokenOwnerGate;
use OCA\Mcp\OAuth\TokenService;
use OCA\Mcp\Service\ConnectionList;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\Storage\InMemoryOAuthStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/** Stored OAuth grants of a fake instance (alice, bob, carol) and the ConnectionList over them. */
final class ConnectionsFixture {
	public InMemoryOAuthStore $store;
	public int $now = 1_000_000;
	/** @var array<string, string> display names by uid */
	public array $names = ['alice' => 'Alice Lima', 'bob' => 'Bob'];
	public InMemoryConfig $config;
	/** The administrator's OAuth clients, whose names the lists show. */
	public \OCA\Mcp\Tests\Unit\OAuth\ClientsTable $clients;

	public function __construct(private TestCase $test) {
		$this->store = new InMemoryOAuthStore();
		$this->config = new InMemoryConfig();
		$this->clients = new \OCA\Mcp\Tests\Unit\OAuth\ClientsTable($test);
	}

	/** Adds a live grant (or an expired one with $expired) and returns its id. */
	public function grant(string $uid, string $clientId, int $createdAgo, bool $expired = false): int {
		$this->store->insertToken([
			'user_id' => $uid, 'client_id' => $clientId, 'resource' => 'https://cloud.test/apps/mcp', 'scope' => 'mcp',
			'access_hash' => 'access-secret-' . $uid . $createdAgo, 'access_expires' => $this->now + 3600,
			'refresh_hash' => 'refresh-secret-' . $uid . $createdAgo,
			'refresh_expires' => $expired ? $this->now - 1 : $this->now + TokenService::REFRESH_TTL,
			'created_at' => $this->now - $createdAgo,
		], $this->now);
		return max(array_keys($this->store->tokens));
	}

	public function list(): ConnectionList {
		$time = $this->mock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn () => $this->now);
		$users = $this->mock(IUserManager::class);
		$users->method('get')->willReturnCallback(function (string $uid) {
			if (!isset($this->names[$uid])) {
				return null;
			}
			$user = $this->mock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn($this->names[$uid]);
			return $user;
		});
		$config = $this->config->mock($this->test);
		$system = $this->mock(IConfig::class);
		$system->method('getSystemValueString')->willReturn('instance-secret');
$writer = InMemoryConfig::writer($config, $this->store);
		$gate = new TokenOwnerGate($users, InMemoryConfig::policy($config, $this->store), $writer,
			$this->mock(\OCP\Config\IUserConfig::class), $this->mock(\OCP\IAppConfig::class), new \Psr\Log\NullLogger(),
			$this->mock(\OCA\Mcp\Access\Policy\CauseProcessor::class));
		$tokens = new TokenService($this->store, new TokenHasher($system), $time, $gate, $writer,
			InMemoryConfig::authUsers($config), InMemoryConfig::processor($config), $this->clients->mapper);
		return new ConnectionList($this->store, $tokens, $users, $time, $this->clients->mapper);
	}

	private function mock(string $class): object {
		return (new \ReflectionMethod($this->test, 'createMock'))->invoke($this->test, $class);
	}
}
