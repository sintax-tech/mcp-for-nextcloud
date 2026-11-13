<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Status;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;

/** Existing NC34/35 cells: the actor's own type, through the status menu, and nobody else's row. */
final class StatusSetTypeTest extends IntegrationTestCase {
	/** @var list<IUser> owned disposable accounts */
	private array $actors = [];
	/** Previous MCP global switch, restored after the test. */
	private ?string $service = null;

	/** @return void remove owned accounts and their status rows, then restore the switch */
	protected function tearDown(): void {
		$db = Server::get(IDBConnection::class);
		foreach ($this->actors as $actor) {
			foreach ([$actor->getUID(), '_' . $actor->getUID()] as $userId) {
				$delete = $db->getQueryBuilder();
				$delete->delete('user_status')->where($delete->expr()->eq('user_id', $delete->createNamedParameter($userId)));
				$delete->executeStatement();
			}
			$actor->delete();
		}
		if ($this->service !== null) {
			$config = Server::get(IConfig::class);
			$this->service === '' ? $config->deleteAppValue('mcp', GrantPolicy::SERVICE_KEY) : $config->setAppValue('mcp', GrantPolicy::SERVICE_KEY, $this->service);
		}
		parent::tearDown();
	}

	/** @return void confirm changes only the actor's type, keeps the message, and deletes the backup row */
	public function testOwnTypeFollowsTheStatusMenu(): void {
		self::assertTrue(Server::get(IAppManager::class)->isEnabledForAnyone('user_status'), 'user_status must be enabled by the existing cell.');
		$uid = 'mcp-it-status-' . bin2hex(random_bytes(4));
		$actor = Server::get(IUserManager::class)->createUser($uid, bin2hex(random_bytes(24)));
		self::assertInstanceOf(IUser::class, $actor);
		$this->actors[] = $actor;
		$otherUid = 'mcp-it-status-' . bin2hex(random_bytes(4));
		$other = Server::get(IUserManager::class)->createUser($otherUid, bin2hex(random_bytes(24)));
		self::assertInstanceOf(IUser::class, $other);
		$this->actors[] = $other;
		$config = Server::get(IConfig::class);
		$this->service = $config->getAppValue('mcp', GrantPolicy::SERVICE_KEY, '');
		$policy = Server::get(GrantPolicy::class);
		$policy->setGlobalEnabled(true);
		$policy->setEligible($uid, true);
		$policy->setConnected($uid, true);
		$client = new McpClient(LoginFactory::basic($uid));
		self::assertSame([], $this->rows($uid));
		$created = $client->write('status_set_type', ['status' => 'online']);
		self::assertFalse($created->ok(), 'status.edit stays denied');
		self::assertSame([], $this->rows($uid), 'a denied call does not create a row');
		$policy->setGrant($uid, 'status', 'edit', true);
		$created = $client->write('status_set_type', ['status' => 'online']);
		self::assertTrue($created->ok(), $created->describe());
		self::assertSame('online', $created->json()['status']);
		self::assertFalse($created->json()['removed_backup']);
		self::assertSame('online', $this->rows($uid)[0]['status']);
		$delete = Server::get(IDBConnection::class)->getQueryBuilder();
		$delete->delete('user_status')->where($delete->expr()->eq('user_id', $delete->createNamedParameter($uid)));
		$delete->executeStatement();
		$this->insert($uid, 'dnd', null, 'At the desk', null, 1_600_000_100);
		$this->insert('_' . $uid, 'away', null, 'backup-secret', null, null, true);
		$this->insert($otherUid, 'online', null, 'foreign-secret', null, null);
		$otherBefore = $this->rows($otherUid);
		$storedMessage = $this->rows($uid)[0]['custom_message'];
		$plan = $client->tool('status_set_type', ['status' => 'away']);
		self::assertTrue($plan->ok(), $plan->describe());
		self::assertTrue($plan->structured()['requiresConfirmation']);
		self::assertSame('dnd', $plan->structured()['before']);
		self::assertSame('away', $plan->structured()['after']);
		self::assertSame('dnd', $this->rows($uid)[0]['status'], 'a plan does not write');
		self::assertNotSame([], $this->rows('_' . $uid));
		$refused = $client->write('status_set_type', ['status' => 'busy', 'user_id' => $otherUid]);
		self::assertFalse($refused->ok(), 'another user id is not an argument');
		self::assertSame('dnd', $this->rows($uid)[0]['status']);
		self::assertSame($otherBefore, $this->rows($otherUid));
		$invalid = $client->write('status_set_type', ['status' => 'vacation']);
		self::assertFalse($invalid->ok(), 'a type outside the native enum is refused');
		self::assertSame('dnd', $this->rows($uid)[0]['status']);
		$written = $client->write('status_set_type', ['status' => 'away']);
		self::assertTrue($written->ok(), $written->describe());
		$payload = $written->json();
		self::assertSame('away', $payload['status']);
		self::assertSame('At the desk', $payload['message']);
		self::assertTrue($payload['removed_backup']);
		self::assertFalse($payload['live_presence']);
		self::assertSame('away', $this->rows($uid)[0]['status']);
		self::assertSame($storedMessage, $this->rows($uid)[0]['custom_message']);
		self::assertSame([], $this->rows('_' . $uid), 'the temporary backup is removed');
		self::assertSame($otherBefore, $this->rows($otherUid));
		self::assertStringNotContainsString('backup-secret', $written->fingerprint());
		self::assertStringNotContainsString('foreign-secret', $written->fingerprint());
	}

	/**
	 * @param string $userId row owner, including a backup id
	 * @param string $status stored type
	 * @param string|null $messageId stored message id
	 * @param string|null $customMessage stored custom text
	 * @param string|null $customIcon stored icon
	 * @param int|null $clearAt stored clear-at
	 * @param bool $backup whether the row is a backup
	 * @return void
	 */
	private function insert(string $userId, string $status, ?string $messageId, ?string $customMessage, ?string $customIcon, ?int $clearAt, bool $backup = false): void {
		$insert = Server::get(IDBConnection::class)->getQueryBuilder();
		$insert->insert('user_status')->values([
			'user_id' => $insert->createNamedParameter($userId),
			'status' => $insert->createNamedParameter($status),
			'status_timestamp' => $insert->createNamedParameter(1600000000, IQueryBuilder::PARAM_INT),
			'is_user_defined' => $insert->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
			'message_id' => $insert->createNamedParameter($messageId),
			'custom_icon' => $insert->createNamedParameter($customIcon),
			'custom_message' => $insert->createNamedParameter($customMessage),
			'clear_at' => $insert->createNamedParameter($clearAt, $clearAt === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
			'is_backup' => $insert->createNamedParameter($backup, IQueryBuilder::PARAM_BOOL),
			'status_message_timestamp' => $insert->createNamedParameter(1600000001, IQueryBuilder::PARAM_INT),
		]);
		$insert->executeStatement();
	}

	/**
	 * @param string $userId row owner
	 * @return list<array<string, mixed>> stored rows
	 */
	private function rows(string $userId): array {
		$query = Server::get(IDBConnection::class)->getQueryBuilder();
		$query->select('*')->from('user_status')->where($query->expr()->eq('user_id', $query->createNamedParameter($userId)));
		return $query->executeQuery()->fetchAllAssociative();
	}
}
