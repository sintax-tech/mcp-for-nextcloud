<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Talk;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Platform\Compat\AppEnablement;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\EventProbe;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserManager;
use OCP\Server;

/**
 * talk_list_unread over the real Talk of the cell.
 *
 * The unit suite cannot load OCA\Talk, so this is the proof that the read writes nothing: the room row and the
 * attendee counters are identical around the call, and the lobby and room events stay at zero. Setup may create
 * the room and the unread message. The actor who must be barred by a lobby is Bob, a guest of Alice's group.
 */
final class UnreadListTest extends IntegrationTestCase {
	/** @var list<string> */
	private const ROOM_EVENTS = [
		'OCA\\Talk\\Events\\BeforeLobbyModifiedEvent',
		'OCA\\Talk\\Events\\LobbyModifiedEvent',
		'OCA\\Talk\\Events\\BeforeRoomModifiedEvent',
		'OCA\\Talk\\Events\\RoomModifiedEvent',
	];

	/** @var array<string, array<string, bool>> */
	private array $grants = [];

	private bool $ready = false;

	protected function setUp(): void {
		parent::setUp();
		if (!AppEnablement::forAnyone(Server::get(IAppManager::class), 'spreed')) {
			$this->markTestSkipped('The Talk app is not enabled in this cell; see provision.log in its artifact');
		}
		$policy = Server::get(GrantPolicy::class);
		$policy->setGlobalEnabled(true);
		foreach ([Fixtures::ALICE, Fixtures::BOB] as $uid) {
			$policy->setEligible($uid, true);
			$policy->setConnected($uid, true);
			foreach (['read', 'create', 'reply'] as $operation) {
				$this->grants[$uid][$operation] = $policy->granted($uid, 'talk', $operation);
				$policy->setGrant($uid, 'talk', $operation, true);
			}
		}
		$this->ready = true;
	}

	protected function tearDown(): void {
		if ($this->ready) {
			$policy = Server::get(GrantPolicy::class);
			foreach ($this->grants as $uid => $operations) {
				foreach ($operations as $operation => $enabled) {
					$policy->setGrant($uid, 'talk', $operation, $enabled);
				}
			}
		}
		parent::tearDown();
	}

	public function testAnUnreadConversationIsListed(): void {
		$token = $this->group('unread');
		$this->say($token, 'unread');
		$before = $this->row($token, Fixtures::BOB);

		$tokens = $this->during(fn (): array => $this->allTokens($this->bob()));

		$this->assertContains($token, $tokens);
		$this->assertSame($before, $this->row($token, Fixtures::BOB));
	}

	public function testAnActiveLobbyIsOmittedAndWritesNothing(): void {
		$token = $this->group('active');
		$this->say($token, 'behind the lobby');
		$this->lobby($token, new \DateTime('+2 days'));
		$before = $this->row($token, Fixtures::BOB);

		$tokens = $this->during(fn (): array => $this->allTokens($this->bob()));

		$this->assertNotContains($token, $tokens);
		$this->assertSame($before, $this->row($token, Fixtures::BOB));
		$this->assertNotSame('0', $before['lobby_state']);
	}

	public function testAnExpiredLobbyStaysStoredAndIsListed(): void {
		$token = $this->group('elapsed');
		$this->say($token, 'timer already passed');
		$this->lobby($token, new \DateTime('-2 days'));
		$before = $this->row($token, Fixtures::BOB);

		$found = $this->during(fn (): ?array => $this->find($this->bob(), $token));

		$this->assertIsArray($found);
		$this->assertSame($before, $this->row($token, Fixtures::BOB));
		$this->assertNotSame('0', $before['lobby_state']);
		$this->assertNotNull($before['lobby_timer']);
	}

	public function testAnUnnamedRoomKeepsAnEmptyName(): void {
		$token = $this->group('unnamed');
		$this->clearName($token);
		$this->say($token, 'no stored name');
		$before = $this->row($token, Fixtures::BOB);
		$this->assertSame('', $before['name']);

		$found = $this->during(fn (): ?array => $this->find($this->bob(), $token));

		$this->assertIsArray($found);
		$this->assertSame('', $found['displayName']);
		$this->assertSame($before, $this->row($token, Fixtures::BOB));
	}

	public function testAMessageBetweenPagesMovesTheConversationOffTheContinuation(): void {
		$tokens = [$this->group('page-a'), $this->group('page-b'), $this->group('page-c')];
		foreach ($tokens as $token) {
			$this->say($token, 'page ' . $token);
		}
		$before = [];
		foreach ($tokens as $token) {
			$before[$token] = $this->row($token, Fixtures::BOB);
		}
		$bob = $this->bob();
		$seen = $this->during(function () use ($bob, $tokens): array {
			$seen = [];
			$cursor = null;
			for ($step = 0; $step < 60 && count(array_intersect($tokens, $seen)) < 3; $step++) {
				$body = $this->page($bob, 1, $cursor);
				foreach ($body['conversations'] as $row) {
					$seen[] = (string)$row['token'];
				}
				$cursor = $body['nextCursor'];
				if (!is_string($cursor)) {
					break;
				}
			}
			return $seen;
		});
		$this->assertSame([], array_diff($tokens, $seen), 'the three rooms are reachable before any bump');
		$first = $this->during(fn (): array => $this->page($bob, 1, null));
		$firstToken = (string)$first['conversations'][0]['token'];
		$bump = $tokens[0] === $firstToken ? $tokens[1] : $tokens[0];
		$this->activity($bump, new \DateTime('2099-01-01 00:00:00'));
		$second = $this->during(fn (): array => $this->page($bob, 1, $first['nextCursor']));
		$fresh = $this->during(fn (): array => $this->allTokens($bob));

		$this->assertNotContains($bump, array_column($second['conversations'], 'token'));
		foreach ($tokens as $token) {
			$this->assertContains($token, $fresh);
			$after = $this->row($token, Fixtures::BOB);
			if ($token === $bump) {
				$this->assertNotSame($before[$token]['last_activity'], $after['last_activity']);
				unset($before[$token]['last_activity'], $after['last_activity']);
			}
			$this->assertSame($before[$token], $after);
		}
	}

	/** @param callable(): mixed $read one or more tool calls */
	private function during(callable $read): mixed {
		$probe = new EventProbe(Server::get(IEventDispatcher::class), $this->official());
		foreach (self::ROOM_EVENTS as $class) {
			$this->assertTrue(class_exists($class), $class);
			$probe->record($class);
		}
		try {
			$result = $read();
		} finally {
			$seen = $probe->classes();
			$probe->detach();
		}
		$this->assertSame([], $seen);
		return $result;
	}

	private function group(string $label): string {
		$reply = $this->alice()->write('talk_create_group', [
			'name' => 'mcp-tk1-' . $label . '-' . bin2hex(random_bytes(4)),
			'participants' => [Fixtures::BOB],
		]);
		$this->assertTrue($reply->ok(), 'talk_create_group: ' . $reply->describe());
		$token = (string)($reply->json()['conversation_token'] ?? '');
		$this->assertNotSame('', $token);
		return $token;
	}

	private function say(string $token, string $message): void {
		$reply = $this->alice()->write('talk_reply', ['conversation_token' => $token, 'message' => $message]);
		$this->assertTrue($reply->ok(), 'talk_reply: ' . $reply->describe());
	}

	private function lobby(string $token, \DateTime $timer): void {
		$room = Server::get(\OCA\Talk\Manager::class)->getRoomByToken($token);
		Server::get(\OCA\Talk\Service\RoomService::class)->setLobby(
			$room,
			\OCA\Talk\Webinary::LOBBY_NON_MODERATORS,
			$timer,
		);
	}

	private function clearName(string $token): void {
		$update = $this->official()->getQueryBuilder();
		$update->update('talk_rooms')
			->set('name', $update->createNamedParameter(''))
			->where($update->expr()->eq('token', $update->createNamedParameter($token)));
		$this->assertSame(1, $update->executeStatement());
	}

	private function activity(string $token, \DateTime $when): void {
		$update = $this->official()->getQueryBuilder();
		$update->update('talk_rooms')
			->set('last_activity', $update->createNamedParameter($when, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->where($update->expr()->eq('token', $update->createNamedParameter($token)));
		$this->assertSame(1, $update->executeStatement());
	}

	/** @return array<string, string|null> */
	private function row(string $token, string $uid): array {
		$query = $this->official()->getQueryBuilder();
		$query->select('r.name', 'r.lobby_state', 'r.lobby_timer', 'r.last_activity', 'a.unread_messages', 'a.last_read_message')
			->from('talk_rooms', 'r')
			->innerJoin('r', 'talk_attendees', 'a', 'a.room_id = r.id')
			->where($query->expr()->eq('r.token', $query->createNamedParameter($token)))
			->andWhere($query->expr()->eq('a.actor_type', $query->createNamedParameter('users')))
			->andWhere($query->expr()->eq('a.actor_id', $query->createNamedParameter($uid)));
		$result = $query->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		$this->assertIsArray($row, $token);
		$normalized = [];
		foreach ($row as $column => $value) {
			$normalized[(string)$column] = $value === null ? null : (string)$value;
		}
		return $normalized;
	}

	/** @return list<string> */
	private function allTokens(McpClient $client): array {
		$tokens = [];
		$cursor = null;
		for ($page = 0; $page < 40; $page++) {
			$body = $this->page($client, 50, $cursor);
			foreach ($body['conversations'] as $conversation) {
				$tokens[] = (string)$conversation['token'];
			}
			$cursor = $body['nextCursor'];
			if (!is_string($cursor)) {
				return $tokens;
			}
		}
		$this->fail('unread list did not end');
	}

	/** @return array{conversations: list<array<string, mixed>>, nextCursor: string|null} */
	private function page(McpClient $client, int $limit, ?string $cursor): array {
		$arguments = ['limit' => $limit];
		if ($cursor !== null) {
			$arguments['cursor'] = $cursor;
		}
		$reply = $client->tool('talk_list_unread', $arguments);
		$this->assertTrue($reply->ok(), $reply->describe());
		$body = $reply->json();
		$this->assertIsArray($body);
		$this->assertIsArray($body['conversations'] ?? null);
		return $body;
	}

	/** @return array<string, mixed>|null */
	private function find(McpClient $client, string $token): ?array {
		foreach ($this->allTokens($client) as $seen) {
			if ($seen === $token) {
				$cursor = null;
				for ($page = 0; $page < 40; $page++) {
					$body = $this->page($client, 50, $cursor);
					foreach ($body['conversations'] as $conversation) {
						if (($conversation['token'] ?? null) === $token) {
							return $conversation;
						}
					}
					$cursor = $body['nextCursor'];
					if (!is_string($cursor)) {
						return null;
					}
				}
			}
		}
		return null;
	}

	private function alice(): McpClient {
		return new McpClient(LoginFactory::basic(Fixtures::ALICE));
	}

	private function bob(): McpClient {
		return new McpClient(LoginFactory::basic(Fixtures::BOB));
	}
}
