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

/** Existing NC34/35 cells: own configured snapshot, missing row stays missing, expired clear_at stays stored. */
final class StatusReadTest extends IntegrationTestCase {
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

	/** @return void the tool reads the actor's row and leaves every stored status row unchanged */
	public function testOwnSnapshotDoesNotCreateOrExpireTheRow(): void {
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
		self::assertFalse($client->tool('status_read_own')->ok(), 'status.read stays opt-in');
		$policy->setGrant($uid, 'status', 'read', true);
		$empty = $this->rows($uid);
		self::assertSame([], $empty);
		$missing = $client->tool('status_read_own');
		self::assertTrue($missing->ok(), $missing->describe());
		self::assertFalse($missing->json()['configured']);
		self::assertSame('No status is configured.', $missing->json()['message']);
		self::assertSame($empty, $this->rows($uid), 'a missing row is not created');
		$this->insert($uid, 'dnd', 'meeting', null, 'SECRET-ICON', 1600000100);
		$this->insert('_' . $uid, 'away', null, 'backup-secret', null, null, true);
		$this->insert($otherUid, 'online', null, 'foreign-secret', null, null);
		$before = ['own' => $this->rows($uid), 'backup' => $this->rows('_' . $uid), 'other' => $this->rows($otherUid)];
		$read = $client->tool('status_read_own');
		self::assertTrue($read->ok(), $read->describe());
		$payload = $read->json();
		self::assertTrue($payload['configured']);
		self::assertSame('dnd', $payload['status']);
		self::assertSame('meeting', $payload['message_id']);
		self::assertSame('In a meeting', $payload['message']);
		self::assertSame(1600000100, $payload['clear_at']);
		self::assertTrue($payload['expired']);
		self::assertFalse($payload['live_presence']);
		self::assertSame('configured', $payload['snapshot']);
		self::assertStringNotContainsString('SECRET-ICON', $read->fingerprint());
		self::assertStringNotContainsString('backup-secret', $read->fingerprint());
		self::assertStringNotContainsString('foreign-secret', $read->fingerprint());
		self::assertSame($before, ['own' => $this->rows($uid), 'backup' => $this->rows('_' . $uid), 'other' => $this->rows($otherUid)]);
		$update = Server::get(IDBConnection::class)->getQueryBuilder();
		$update->update('user_status')
			->set('custom_message', $update->createNamedParameter('At the desk'))
			->set('clear_at', $update->createNamedParameter(null))
			->where($update->expr()->eq('user_id', $update->createNamedParameter($uid)));
		self::assertSame(1, $update->executeStatement());
		$stored = $this->rows($uid);
		$again = $client->tool('status_read_own');
		self::assertTrue($again->ok(), $again->describe());
		self::assertSame('At the desk', $again->json()['message']);
		self::assertNull($again->json()['clear_at']);
		self::assertFalse($again->json()['expired']);
		self::assertSame('dnd', $again->json()['status']);
		self::assertSame($stored, $this->rows($uid), 'reading the custom message does not write the row');
	}

	/**
	 * @param string $userId row owner, including a backup id
	 * @param string $status stored type
	 * @param string|null $messageId stored message id
	 * @param string|null $customMessage stored custom text
	 * @param string|null $customIcon stored icon that the tool must not return
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
