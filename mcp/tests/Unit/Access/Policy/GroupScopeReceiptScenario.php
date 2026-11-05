<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\Policy;

use OCA\Mcp\Access\Policy\PolicyConflict;
use OCA\Mcp\Access\Policy\PolicyEventPayload;
use OCA\Mcp\Access\Policy\PolicyEventProcessor;
use OCA\Mcp\Access\Policy\PolicyOutbox;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\PolicyWriter;
use OCA\Mcp\Access\Policy\ScopeItemsInaccessible;
use OCA\Mcp\Access\Policy\SnapshotInvalidated;
use OCA\Mcp\Access\ResourceScope\NativeResourceEnumerator;
use OCA\Mcp\Access\ResourceScope\ScopeActivation;
use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\GroupLifeMapper;
use OCA\Mcp\Db\IdentityKey;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\PolicyStateSeeder;
use OCA\Mcp\Db\RecheckMapper;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Migration\Version000500Date20260930000000;
use OCA\Mcp\Migration\Version000700Date20261001000000;
use OCA\Mcp\Migration\Version000800Date20261002000000;
use OCA\Mcp\Migration\Version000800Date20261002010000;
use OCA\Mcp\Migration\Version001002Date20261008000000;
use OCA\Mcp\Migration\Version001100Date20261014000000;
use OCA\Mcp\Migration\Version001100Date20261015000000;
use OCA\Mcp\Migration\Version001100Date20261015010000;
use OCA\Mcp\Migration\Version001100Date20261015020000;
use OCA\Mcp\OAuth\Storage\OAuthStore;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/** Records group, scope and receipt commands through the facade on the SQLite rig. */
final class GroupScopeReceiptScenario extends TestCase {
	public const CASES = [
		'preview-no-revision', 'save-noop', 'save-change', 'empty-deletes-life', 'withdraw-holders',
		'hash-before-begin', 'untrusted-nonempty', 'untrusted-empty', 'active-renewal', 'disagreeing-sources',
		'preview-foreign', 'preview-expired', 'preview-foreign-expired', 'scope-invalid-actor', 'scope-invalid-module',
		'scope-barrier-closed', 'scope-inaccessible', 'scope-noop', 'scope-change', 'undo-grant', 'undo-scope',
		'undo-deleted-user', 'undo-invalid-receipt', 'undo-foreign-expired', 'undo-own-expired', 'receipt-users-expired',
		'life-then-failure', 'open-transaction', 'prepare-opens-transaction', 'stale-revision', 'snapshot-retries',
		'delete-gen-rejected', 'cut-stranger', 'h1-changed', 'save-renews-source', 'command-id-stable',
	];

	public function record(string $case): array {
		$trace = [];
		$database = new SqliteDatabase($this, [
			new Version000500Date20260930000000(), new Version000700Date20261001000000(),
			new Version000800Date20261002000000(), new Version000800Date20261002010000(),
			new Version001002Date20261008000000(), new Version001100Date20261014000000(),
			new Version001100Date20261015000000($this->createMock(IDBConnection::class)),
			new Version001100Date20261015010000(), new Version001100Date20261015020000(),
		]);
		$db = $database->connection();
		(new PolicyStateSeeder($db))->seed();
		$native = $database->dbal->getNativeConnection();
		$native->sqliteCreateFunction('mcp_log', static function (string $op) use (&$trace): int {
			$trace[] = $op;
			return 1;
		});
		foreach ([
			['mcp_group_life', 'life'], ['mcp_group_grants', 'grants'], ['mcp_policy_events', 'event'],
			['mcp_auth_users', 'auth'], ['mcp_policy_state', 'state'], ['mcp_scope_modes', 'scope_mode'],
			['mcp_scope_items', 'scope_item'],
		] as [$table, $name]) {
			foreach (['INSERT' => 'insert', 'UPDATE' => 'update', 'DELETE' => 'delete'] as $sql => $verb) {
				$database->dbal->executeStatement('CREATE TRIGGER mcp_log_' . $name . '_' . $verb . ' AFTER ' . $sql . ' ON ' . $table . " BEGIN SELECT mcp_log('" . $name . '_' . $verb . "'); END");
			}
		}
		$now = 1_000_000;
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(static fn (): int => $now);
		$events = new PolicyEventsMapper($db, $time);
		$state = new PolicyStateMapper($db);
		$life = new GroupLifeMapper($db);
		$grants = new GroupGrantsMapper($db);
		$scopes = new ResourceScopeMapper($db);
		$auth = new AuthUsersMapper($db);
		$config = new InMemoryConfig();
		$config->user['alice']['mcp'] = ['grant_files_read' => '0'];
		$cleared = [];
		$iconfig = $config->mock($this);
		$userConfig = \Closure::bind(static fn (object $stored) => self::$userConfigs[$stored], null, InMemoryConfig::class)($iconfig);
		$userConfig->method('clearCache')->willReturnCallback(function ($uid) use (&$cleared): void { $cleared[] = $uid; });
		$apps = $this->createMock(IAppConfig::class);
		$apps->method('clearCache')->willReturnCallback(static function (): void {});
		$apps->method('hasKey')->willReturn(false);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(function (string $uid) use ($case) {
			return $case === 'undo-deleted-user' ? null : $this->createMock(\OCP\IUser::class);
		});
		$holderCalls = 0;
		$oauth = $this->createMock(OAuthStore::class);
		$oauth->method('liveHolders')->willReturnCallback(function () use (&$holderCalls, &$trace, $case): array {
			++$holderCalls;
			$trace[] = 'holders-' . $holderCalls;
			$rows = [hash('sha256', 'alice') => ['uid' => 'alice', 'gen' => '3']];
			if ($case === 'h1-changed' && $holderCalls % 2 === 0) {
				$rows[hash('sha256', 'bob')] = ['uid' => 'bob', 'gen' => '1'];
			}
			return $rows;
		});
		$resources = $this->createMock(NativeResourceEnumerator::class);
		$resources->method('inaccessible')->willReturnCallback(function (string $uid, string $module, array $items) use (&$trace, $case): array {
			$trace[] = 'inaccessible';
			return $case === 'scope-inaccessible' ? ['10', '2'] : [];
		});
		$processor = $this->createMock(PolicyEventProcessor::class);
		$processor->method('afterCommit')->willReturn(true);
		$closed = in_array($case, ['scope-invalid-actor', 'scope-invalid-module', 'scope-barrier-closed'], true);
		$writer = new PolicyWriter($db, $state, $events, $iconfig, $userConfig, $apps, $users, $time, $auth,
			new RecheckMapper($db), $processor, $grants, $life, $oauth, $scopes, $resources,
			new ScopeActivation($closed ? ['files' => false, 'calendar' => false, 'tasks' => false] : ScopeActivation::MODULES));
		$genA = str_repeat('a', 32);
		$genB = str_repeat('b', 32);
		$files = ['files' => ['read']];
		$eligible = ['_system' => ['eligible']];
		$seed = function (string $gid, array $set, ?string $gen = null) use ($database): void {
			foreach ($set as $module => $operations) {
				foreach ($operations as $operation) {
					$database->dbal->insert('mcp_group_grants', ['group_key' => IdentityKey::group($gid), 'group_id' => $gid, 'module' => $module, 'operation' => $operation]);
				}
			}
			if ($gen !== null) {
				$database->dbal->insert('mcp_group_life', ['group_key' => IdentityKey::group($gid), 'group_id' => $gid, 'state' => 'active', 'gen' => $gen, 'policy_rev' => 0]);
			}
		};
		if (in_array($case, ['save-noop', 'empty-deletes-life', 'untrusted-empty', 'delete-gen-rejected'], true)) {
			$seed('staff', $files, $genA);
		}
		if (in_array($case, ['withdraw-holders', 'h1-changed', 'cut-stranger'], true)) {
			$seed('staff', $eligible, $genA);
			$database->dbal->insert('mcp_auth_users', ['user_key' => IdentityKey::user('alice'), 'user_id' => 'alice', 'issue_seq' => 4, 'revoked_below' => 0]);
		}
		if (in_array($case, ['active-renewal', 'save-renews-source'], true)) {
			$seed('staff', [], $genA);
		}
		if ($case === 'save-renews-source') {
			$seed('other', [], $genA);
		}
		if ($case === 'scope-noop' || $case === 'undo-scope') {
			$database->dbal->insert('mcp_scope_modes', ['user_key' => IdentityKey::user('alice'), 'module' => 'files', 'user_id' => 'alice', 'policy_rev' => '0']);
			foreach (['1', '2'] as $id) {
				$database->dbal->insert('mcp_scope_items', ['user_key' => IdentityKey::user('alice'), 'module' => 'files', 'resource_key' => IdentityKey::resource('files', $id), 'resource_id' => $id]);
			}
		}
		$plant = function (string $id, string $kind, array $payload, int $createdAt) use ($database): void {
			$database->dbal->insert('mcp_policy_events', [
				'id' => $id, 'kind' => $kind, 'status' => 'pending',
				'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
				'created_at' => $createdAt, 'attempts' => 0,
			]);
		};
		$storedPreview = PolicyEventPayload::preview('root', 'staff', '0', $files, hash('sha256', '[]'), null);
		if ($case === 'preview-foreign' || $case === 'preview-foreign-expired') {
			$plant(str_repeat('c', 32), PolicyEventsMapper::KIND_PREVIEW, PolicyEventPayload::preview('other', 'staff', '0', $files, hash('sha256', '[]'), null), $case === 'preview-foreign-expired' ? 1 : $now);
		}
		if ($case === 'preview-expired') {
			$plant(str_repeat('c', 32), PolicyEventsMapper::KIND_PREVIEW, $storedPreview, 1);
		}
		$grantChange = ['uid' => 'alice', 'key' => 'grant_files_read', 'before' => '1', 'after' => '0'];
		$scopeChange = ['uid' => 'alice', 'key' => 'scope_files', 'before' => null, 'after' => '["1"]'];
		if (in_array($case, ['undo-grant', 'undo-deleted-user', 'undo-own-expired', 'receipt-users-expired'], true)) {
			$plant(str_repeat('c', 32), PolicyEventsMapper::KIND_RECEIPT, PolicyEventPayload::receipt('root', PolicyEventPayload::ACTION_COMMANDS, '0', '1', [$grantChange]), in_array($case, ['undo-own-expired', 'receipt-users-expired'], true) ? 1 : $now);
		}
		if ($case === 'undo-scope') {
			$plant(str_repeat('c', 32), PolicyEventsMapper::KIND_RECEIPT, PolicyEventPayload::receipt('root', PolicyEventPayload::ACTION_SCOPE, '0', '1', [$scopeChange]), $now);
		}
		if ($case === 'undo-foreign-expired') {
			$plant(str_repeat('c', 32), PolicyEventsMapper::KIND_RECEIPT, PolicyEventPayload::receipt('other', PolicyEventPayload::ACTION_COMMANDS, '0', '1', [$grantChange]), 1);
		}
		if ($case === 'undo-invalid-receipt') {
			$plant(str_repeat('c', 32), PolicyEventsMapper::KIND_RECEIPT, ['v' => 1, 'broken' => true], $now);
		}
		if (str_starts_with($case, 'undo-') || $case === 'receipt-users-expired') {
			$database->dbal->executeStatement('UPDATE mcp_policy_state SET revision = 1');
		}
		$trace = [];
		$phase = 'preview';
		$admit = function (array $request) use (&$phase, &$trace, $case, $db, $database, $genA, $genB): array {
			$trace[] = 'admit-' . $phase . ($request['withdraws'] ? '-withdraw' : '') . ':' . implode(',', $request['holders']);
			if ($case === 'prepare-opens-transaction') {
				$db->beginTransaction();
			}
			if ($case === 'active-renewal' && $phase === 'save') {
				$database->dbal->executeStatement('UPDATE mcp_group_life SET gen = ? WHERE group_id = ?', [$genB, 'staff']);
			}
			if ($case === 'delete-gen-rejected' && $phase === 'save') {
				$writes = 0;
				$hook = function () use (&$writes, &$hook, $database): void {
					$database->beforeStatement = $hook;
					if (++$writes % 2 === 0) {
						$database->dbal->executeStatement('UPDATE mcp_group_life SET gen = ? WHERE group_id = ?', [str_repeat('d', 32), 'staff']);
					}
				};
				$database->beforeStatement = $hook;
			}
			if ($case === 'life-then-failure' && $phase === 'save') {
				$hook = function () use (&$hook, $database): void {
					$database->beforeStatement = $hook;
					$lives = (int)$database->dbal->fetchOne("SELECT COUNT(*) FROM mcp_group_life WHERE group_id = 'staff'");
					if ($lives > 0) {
						throw new \RuntimeException('injected after lifecycle');
					}
				};
				$database->beforeStatement = $hook;
			}
			$members = $case === 'hash-before-begin' && $phase === 'save' ? ['bob'] : ['alice'];
			$cuts = in_array($case, ['withdraw-holders', 'h1-changed'], true) ? ['alice'] : ($case === 'cut-stranger' ? ['bob'] : []);
			$sources = [];
			if ($case === 'disagreeing-sources') {
				$sources = [['groupId' => 'other', 'state' => 'active', 'gen' => $genA], ['groupId' => 'other', 'state' => 'active', 'gen' => $genB]];
			}
			if ($case === 'save-renews-source') {
				$sources = [['groupId' => 'other', 'state' => 'active', 'gen' => $genA]];
			}
			return ['trusted' => !in_array($case, ['untrusted-nonempty', 'untrusted-empty'], true), 'members' => $members, 'cuts' => $cuts, 'sources' => $sources];
		};
		$ids = [];
		$outboxes = [];
		$attempts = 0;
		$result = null;
		$error = null;
		try {
			if ($case === 'open-transaction') {
				$db->beginTransaction();
			}
			if ($case === 'command-id-stable' || $case === 'snapshot-retries') {
				$always = $case === 'snapshot-retries';
				$result = $writer->runAdmin('0', ['alice'], function (array $observed, string $id) use (&$ids): string {
					$ids[] = $id;
					return 'prepared';
				}, function (mixed $snapshot, array $current, string $id, PolicyOutbox $outbox) use (&$attempts, &$outboxes, $always): bool {
					++$attempts;
					$outboxes[] = $outbox;
					if ($always || $attempts < 3) {
						throw new SnapshotInvalidated();
					}
					return false;
				});
			} elseif (str_starts_with($case, 'scope-')) {
				$actor = $case === 'scope-invalid-actor' ? "bad\0actor" : 'root';
				$module = $case === 'scope-invalid-module' ? 'nope' : 'files';
				$result = $writer->setResourceScope('0', $actor, 'alice', $module, ['2', '1']);
			} elseif ($case === 'receipt-users-expired') {
				$result = $writer->receiptUsers(str_repeat('c', 32), 'root');
			} elseif (str_starts_with($case, 'undo-') || $case === 'undo-grant') {
				$result = $writer->restoreReceipt(str_repeat('c', 32), '1', 'root');
			} elseif (in_array($case, ['preview-foreign', 'preview-expired', 'preview-foreign-expired'], true)) {
				$result = $writer->setGroupGrants('0', 'root', 'staff', $files, str_repeat('c', 32), $admit);
			} else {
				$target = match ($case) {
					'save-noop' => $files,
					'empty-deletes-life', 'untrusted-empty', 'delete-gen-rejected' => [],
					'withdraw-holders', 'h1-changed', 'cut-stranger' => $files,
					default => $files,
				};
				$expected = $case === 'stale-revision' ? '4' : '0';
				$saving = in_array($case, ['save-noop', 'save-change', 'empty-deletes-life', 'withdraw-holders', 'hash-before-begin', 'untrusted-nonempty', 'untrusted-empty', 'active-renewal', 'life-then-failure', 'delete-gen-rejected', 'cut-stranger', 'h1-changed', 'save-renews-source'], true);
				if (!$saving) {
					$result = $writer->previewGroupGrants($expected, 'root', 'staff', $target, $admit);
				} else {
					$preview = $writer->previewGroupGrants('0', 'root', 'staff', $target, $admit);
					$phase = 'save';
					$result = $writer->setGroupGrants('0', 'root', 'staff', $preview['grants'], $preview['previewId'], $admit);
				}
			}
		} catch (\Throwable $failure) {
			$error = self::error($failure);
		}
		if ($db->inTransaction()) {
			$db->rollBack();
		}
		$record = [
			'result' => $result,
			'error' => $error,
			'revision' => $state->state()['revision'],
			'life' => $database->dbal->fetchAllAssociative('SELECT group_id, state, gen, policy_rev FROM mcp_group_life ORDER BY group_id'),
			'grants' => $database->dbal->fetchAllAssociative('SELECT group_id, module, operation FROM mcp_group_grants ORDER BY group_id, module, operation'),
			'scopes' => $database->dbal->fetchAllAssociative('SELECT user_id, module, policy_rev FROM mcp_scope_modes ORDER BY user_id, module'),
			'events' => $database->dbal->fetchAllAssociative('SELECT kind, payload FROM mcp_policy_events ORDER BY rowid'),
			'auth' => $database->dbal->fetchAllAssociative('SELECT user_id, issue_seq, revoked_below FROM mcp_auth_users ORDER BY user_id'),
			'userValues' => $config->user,
			'clearedUids' => $cleared,
			'trace' => $trace,
			'attempts' => $attempts,
			'oneCommandId' => $ids === [] ? null : count(array_unique($ids)) === 1,
			'freshOutbox' => $outboxes === [] ? null : count($outboxes) === count(array_unique(array_map(spl_object_id(...), $outboxes))),
		];
		return self::normalize($record);
	}

	/**
	 * Maps only the ids this run created: commandId, a non-uniform gen, and the command prefix of an outbox id.
	 * An input generation, a member hash and any other literal stay as they were written.
	 *
	 * @param array<string, mixed> $record
	 * @return array<string, mixed>
	 */
	public static function normalize(array $record): array {
		$created = [];
		$remember = static function (mixed $value) use (&$created): void {
			if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1 || preg_match('/^(.)\1{31}$/D', $value) === 1) {
				return;
			}
			$created[$value] = true;
		};
		$walk = static function (mixed $node) use (&$walk, $remember): void {
			if (!is_array($node)) {
				return;
			}
			foreach ($node as $key => $value) {
				if ($key === 'commandId' || $key === 'gen') {
					$remember($value);
				}
				if (in_array($key, ['receiptId', 'undoOf', 'previewId'], true) && is_string($value)
					&& preg_match('/^([a-f0-9]{32})-\d+$/D', $value, $prefix) === 1) {
					$remember($prefix[1]);
				}
				if ($key === 'payload' && is_string($value)) {
					$decoded = json_decode($value, true);
					if (is_array($decoded)) {
						$walk($decoded);
					}
					continue;
				}
				if (is_array($value)) {
					$walk($value);
				}
			}
		};
		$walk($record);
		$encoded = json_encode($record, JSON_THROW_ON_ERROR);
		$map = [];
		$encoded = preg_replace_callback('/\b[a-f0-9]{32}\b/', static function (array $match) use ($created, &$map): string {
			if (!isset($created[$match[0]])) {
				return $match[0];
			}
			return $map[$match[0]] ??= 'id-' . (count($map) + 1);
		}, $encoded);
		return json_decode((string)$encoded, true, 512, JSON_THROW_ON_ERROR);
	}

	private static function error(\Throwable $e): array {
		return [
			'class' => $e::class,
			'message' => $e->getMessage(),
			'uncertain' => $e instanceof PolicyUnavailable ? $e->uncertain : null,
			'items' => $e instanceof ScopeItemsInaccessible ? $e->items : null,
			'previous' => $e->getPrevious() === null ? null : self::error($e->getPrevious()),
		];
	}
}
