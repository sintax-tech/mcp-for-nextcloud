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
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Server;

/**
 * Setup and database proof shared by the Talk unread reads of the cell.
 *
 * The tools run in the web server, another process, so the proof that a read writes nothing is the database: the
 * whole room row and Bob's whole attendee row are identical around the call, and Bob has as many Talk sessions and
 * notifications after it as before. Setup may create the room and its messages through the confirmed tools.
 */
trait UnreadDatabase {
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

	/**
	 * Runs the tool calls and proves in the database that they changed nothing Bob or these rooms hold.
	 *
	 * @param list<string> $tokens rooms whose rows must stay identical
	 * @param callable(): mixed $read one or more tool calls
	 */
	private function unchanged(array $tokens, callable $read): mixed {
		$before = $this->state($tokens);
		$result = $read();
		$this->assertSame($before, $this->state($tokens));
		return $result;
	}

	/**
	 * @param list<string> $tokens
	 * @return array{rows: array<string, array{room: array<string, string|null>, attendee: array<string, string|null>}>, sessions: int, notifications: int|null}
	 */
	private function state(array $tokens): array {
		$rows = [];
		foreach ($tokens as $token) {
			$rows[$token] = $this->row($token, Fixtures::BOB);
		}
		$sessions = $this->official()->getQueryBuilder();
		$sessions->select($sessions->func()->count('s.id', 'n'))
			->from('talk_sessions', 's')
			->innerJoin('s', 'talk_attendees', 'a', 'a.id = s.attendee_id')
			->where($sessions->expr()->eq('a.actor_type', $sessions->createNamedParameter('users')))
			->andWhere($sessions->expr()->eq('a.actor_id', $sessions->createNamedParameter(Fixtures::BOB)));
		$state = ['rows' => $rows, 'sessions' => $this->rowCount($sessions), 'notifications' => null];
		// The notifications app owns its table; a cell without it has no notification to compare.
		if ($this->official()->tableExists('notifications')) {
			$notifications = $this->official()->getQueryBuilder();
			$notifications->select($notifications->func()->count('notification_id', 'n'))
				->from('notifications')
				->where($notifications->expr()->eq('user', $notifications->createNamedParameter(Fixtures::BOB)));
			$state['notifications'] = $this->rowCount($notifications);
		}
		return $state;
	}

	private function rowCount(IQueryBuilder $query): int {
		$result = $query->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();
		return (int)$value;
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

	/**
	 * The whole room row and the whole attendee row of this account, every column.
	 *
	 * @return array{room: array<string, string|null>, attendee: array<string, string|null>}
	 */
	private function row(string $token, string $uid): array {
		$room = $this->official()->getQueryBuilder();
		$room->select('*')
			->from('talk_rooms')
			->where($room->expr()->eq('token', $room->createNamedParameter($token)));
		$roomRow = $this->single($room, $token);
		$attendee = $this->official()->getQueryBuilder();
		$attendee->select('*')
			->from('talk_attendees')
			->where($attendee->expr()->eq('room_id', $attendee->createNamedParameter((int)$roomRow['id'], IQueryBuilder::PARAM_INT)))
			->andWhere($attendee->expr()->eq('actor_type', $attendee->createNamedParameter('users')))
			->andWhere($attendee->expr()->eq('actor_id', $attendee->createNamedParameter($uid)));
		return ['room' => $roomRow, 'attendee' => $this->single($attendee, $token . '/' . $uid)];
	}

	/** @return array<string, string|null> the one row of the query, every value as text */
	private function single(IQueryBuilder $query, string $label): array {
		$result = $query->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		$this->assertIsArray($row, $label);
		$normalized = [];
		foreach ($row as $column => $value) {
			$normalized[(string)$column] = $value === null ? null : (string)$value;
		}
		return $normalized;
	}

	private function alice(): McpClient {
		return new McpClient(LoginFactory::basic(Fixtures::ALICE));
	}

	private function bob(): McpClient {
		return new McpClient(LoginFactory::basic(Fixtures::BOB));
	}
}
