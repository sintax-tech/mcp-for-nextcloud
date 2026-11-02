<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\Grants\Commands;

use OCA\Mcp\Access\Policy\{PolicyWriter, PolicyEventProcessor, PolicyOutbox, PolicyUnavailable, SnapshotInvalidated};
use OCA\Mcp\Db\{AuthUsersMapper, GroupGrantsMapper, GroupLifeMapper, PolicyEventsMapper, PolicyStateMapper, RecheckMapper};
use OCA\Mcp\OAuth\Storage\OAuthStore;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\{IConfig, IAppConfig, IDBConnection, IUserManager};
use PHPUnit\Framework\TestCase;

final class GrantScenario extends TestCase {
	public const CASES = ['global-off', 'global-on', 'admin-off', 'eligible-off', 'connected-off', 'grant-on', 'grant-invalid', 'reset', 'reset-absent', 'apply', 'apply-noop', 'apply-mixed', 'apply-duplicates', 'apply-conflicting', 'apply-stale', 'apply-unknown', 'inherit-group', 'inherit-none', 'inherit-unknown', 'inherit-inactive', 'cause', 'issuance'];

	public function record(string $case): array {
		$trace = []; $ids = []; $objects = []; $attempts = 0; $clears = 0; $inside = false; $saved = null;
		$row = ['revision' => '0', 'lock_seq' => '0', 'auth_epoch' => '0'];
		$events = []; $generations = ['issue_seq' => '2', 'revoked_below' => '0'];
		$causes = $case === 'cause' || $case === 'issuance' ? [['causeKey' => 'test', 'gen' => '1']] : [];
		$log = static function (string $op, mixed ...$args) use (&$trace): void { $trace[] = [$op, ...$args]; };
		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturnCallback(function () use (&$inside): bool { return $inside; });
		$db->method('beginTransaction')->willReturnCallback(function () use (&$inside, &$saved, &$row, &$events, &$generations, &$causes, $log): void {
			$log('begin'); $saved = [$row, $events, $generations, $causes]; $inside = true;
		});
		$db->method('commit')->willReturnCallback(function () use (&$inside, $case, $log): void {
			$log('commit'); $inside = false;
			if ($case === 'commit-uncertain') { throw new \RuntimeException('lost commit response'); }
		});
		$db->method('rollBack')->willReturnCallback(function () use (&$inside, &$saved, &$row, &$events, &$generations, &$causes, $case, $log): void {
			$log('rollback');
			if ($case === 'rollback-failed') { throw new \RuntimeException('lost rollback response'); }
			[$row, $events, $generations, $causes] = $saved; $inside = false;
		});
		$state = $this->createMock(PolicyStateMapper::class);
		$state->method('state')->willReturnCallback(function () use (&$row, $log): array { $log('state', $row); return $row; });
		$state->method('acquire')->willReturnCallback(function () use (&$row, $log): void { $log('mutex'); $row['lock_seq'] = (string)((int)$row['lock_seq'] + 1); });
		$state->method('compareAndSet')->willReturnCallback(function ($revision) use (&$row, $log): void { $log('cas', $revision); $row['revision'] = (string)((int)$revision + 1); });
		$state->method('advanceAuthEpoch')->willReturnCallback(function ($epoch) use (&$row, $log): string { $log('epoch', $epoch); return $row['auth_epoch'] = (string)((int)$epoch + 1); });
		$mapper = $this->createMock(PolicyEventsMapper::class);
		$mapper->method('append')->willReturnCallback(function ($id, $kind, $payload) use (&$events, $log): void { $log('append', $id, $kind, $payload); $events[] = compact('id', 'kind', 'payload'); });
		$userConfig = $this->createMock(IUserConfig::class);
		$userConfig->method('clearCache')->willReturnCallback(fn ($uid, $lazy) => $log('user-cache', $uid, $lazy));
		$apps = $this->createMock(IAppConfig::class);
		$apps->method('clearCache')->willReturnCallback(function ($lazy) use (&$clears, $case, $log): void {
			$log('app-cache', ++$clears, $lazy);
			$fail = ['cache-before' => 1, 'cache-local' => 2, 'cache-after' => 3, 'cache-after-failure' => 3][$case] ?? 0;
			if ($clears === $fail) { throw new \RuntimeException('injected cache failure'); }
		});
		$auth = $this->createMock(AuthUsersMapper::class);
		$auth->method('generations')->willReturnCallback(function ($uid) use (&$generations, $log): array { $log('generations', $uid, $generations); return $generations; });
		$auth->method('cut')->willReturnCallback(function ($uid, $cut) use (&$generations, $log): bool { $log('cut', $uid, $cut); $changed = $generations['revoked_below'] !== $cut; $generations['revoked_below'] = $cut; return $changed; });
		$rechecks = $this->createMock(RecheckMapper::class);
		$rechecks->method('causes')->willReturnCallback(function ($uid) use (&$causes, $log): array { $log('causes', $uid, $causes); return $causes; });
		$rechecks->method('consume')->willReturnCallback(function ($uid, $key, $gen) use (&$causes, $log): bool { $log('consume', $uid, $key, $gen); $causes = []; return true; });
		$processor = $this->createMock(PolicyEventProcessor::class);
		$processor->method('afterCommit')->willReturnCallback(function ($outbox) use ($case, &$inside, $log): bool { self::assertFalse($inside); $log('delivery', $outbox); return $case !== 'cleanup-pending'; });
		$data = new InMemoryConfig();
		$config = $data->mock($this); $data->app['mcp']['enabled'] = '0';
		$data->user['alice']['mcp'] = ['eligible' => '1', 'connected' => '1', 'grant_files_read' => 'MIXED'];
		$apps->method('hasKey')->willReturnCallback(fn ($app, $key) => array_key_exists($key, $data->app[$app] ?? []));
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn ($uid) => $case === 'apply-unknown' ? null : $this->createMock(\OCP\IUser::class));
		$life = $this->createMock(GroupLifeMapper::class);
		$life->method('forGroups')->willReturnCallback(function ($groups) use ($case, $log): array { $log('lifecycles', $groups); return [['groupId' => 'staff', 'state' => $case === 'inherit-inactive' ? 'pending_delete' : GroupLifeMapper::ACTIVE, 'gen' => str_repeat('a', 32)]]; });
		$life->method('transition')->willReturnCallback(function (...$args) use ($log): bool { $log('renew', ...$args); return true; });
		$writer = new PolicyWriter($db, $state, $mapper, $config, $userConfig, $apps, $users, $this->createMock(ITimeFactory::class), $auth, $rechecks, $processor, new GroupGrantsMapper($db), $life, $this->createMock(OAuthStore::class));
		$prepare = function ($observed, $id) use (&$inside, &$ids, $case, $log): string {
			self::assertFalse($inside); self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $id); $ids[] = $id;
			$log('prepare', $observed, $id); if ($case === 'external-transaction') { $inside = true; } return 'prepared';
		};
		$apply = function ($snapshot, $current, $id, PolicyOutbox $outbox) use (&$inside, &$attempts, &$objects, $case, $log, $writer): mixed {
			self::assertTrue($inside); self::assertSame('prepared', $snapshot); ++$attempts; $objects[] = $outbox;
			$writer->holdLifecycles([]); $log('apply', $current, $id, $attempts);
			$outbox->add(PolicyEventsMapper::KIND_AUDIT, ['attempt' => $attempts]);
			if (in_array($case, ['snapshot-retry', 'expected-retry'], true) && $attempts === 1) { throw new SnapshotInvalidated(); }
			if ($case === 'exhausted') { throw new SnapshotInvalidated(); }
			if ($case === 'deadlock' && $attempts === 1) { throw self::deadlock(); }
			if ($case === 'rollback-failed' || $case === 'cache-after-failure') { throw new \LogicException('local failed'); }
			return $case === 'invalid-return' ? 1 : $case !== 'noop';
		};
		$result = null; $error = null;
		try {
			if (in_array($case, ['global-off', 'global-on'], true)) { $writer->setGlobalEnabled($case === 'global-on'); }
			elseif ($case === 'admin-off') { $result = $writer->adminSetGlobalEnabled('0', 'root', false); }
			elseif ($case === 'eligible-off') { $writer->setEligible('alice', false); }
			elseif ($case === 'connected-off') { $writer->setConnected('alice', false); }
			elseif ($case === 'grant-on' || $case === 'grant-invalid') { $writer->setGrant('alice', $case === 'grant-invalid' ? 'unknown' : 'files', 'read', true); }
			elseif ($case === 'reset' || $case === 'reset-absent') { $writer->resetOverride('alice', 'files', $case === 'reset' ? 'read' : 'write'); }
			elseif (str_starts_with($case, 'apply') || str_starts_with($case, 'inherit-')) {
				$cell = ['uid' => 'alice', 'key' => 'eligible', 'value' => '0'];
				if ($case === 'apply-noop') { $cell['value'] = '1'; }
				if ($case === 'apply-mixed') { $cell['key'] = 'grant_files_read'; $cell['value'] = null; }
				if (str_starts_with($case, 'inherit-')) { $cell['value'] = null; }
				$cells = [$cell];
				if ($case === 'apply-duplicates') { $cells[] = $cell; }
				if ($case === 'apply-conflicting') { $cells[] = array_replace($cell, ['value' => '1']); }
				$inherit = function ($uid) use ($case, &$inside, $log): ?array {
					self::assertFalse($inside); $log('inheritance', $uid);
					return match ($case) { 'inherit-unknown' => throw new PolicyUnavailable('unknown source'), 'inherit-none' => null, default => [['groupId' => 'staff', 'gen' => str_repeat('a', 32)]] };
				};
				$result = $writer->applyCells($case === 'apply-stale' ? '1' : '0', 'root', 'grants.commands', $cells, str_starts_with($case, 'inherit-') ? $inherit : null);
			}
			elseif ($case === 'cut') { $writer->revokeCredentials('alice'); }
			elseif ($case === 'epoch') { $writer->setGlobalEnabled(false); }
			elseif ($case === 'cause') { $result = $writer->processCauses('alice', fn () => ['test' => false]); }
			elseif ($case === 'issuance') {
				$result = $writer->runCredential('alice', fn () => 'issuance', function ($snapshot, $current, $generation) use ($log): bool { $log('issued-after-cut', $snapshot, $current, $generation); return false; }, fn () => ['test' => true]);
			} elseif ($case === 'under-mutex') { $result = $writer->underMutex(function () use (&$inside): string { self::assertTrue($inside); return 'local-result'; }); }
			else { if ($case === 'nested') { $inside = true; } $result = $writer->runAdmin($case === 'apply-stale' ? '1' : '0', ['alice', 'alice', 'bob'], $prepare, $apply); }
		} catch (\Throwable $failure) { $error = self::error($failure); }
		self::assertLessThanOrEqual(1, count(array_unique($ids)), 'logical command id must survive retry');
		self::assertSame(count($objects), count(array_unique(array_map(spl_object_id(...), $objects))), 'outbox is fresh per attempt');
		try { $writer->holdLifecycles([]); $outside = 'accepted'; } catch (\LogicException $e) { $outside = $e->getMessage(); }
		try { $writer->assertUsable(); $usable = true; } catch (PolicyUnavailable) { $usable = false; }
		$appConfig = $data->app; $userValues = $data->user; $configAccess = $data->accessed;
		$record = compact('appConfig', 'userValues', 'configAccess', 'result', 'error', 'trace', 'row', 'events', 'generations', 'causes', 'attempts', 'outside', 'usable', 'inside');
		// Replace only random logical command IDs, retaining their bijection, suffixes and retry relationships.
		$encoded = json_encode($record, JSON_THROW_ON_ERROR);
		$seen = []; $encoded = preg_replace_callback('/\b[a-f0-9]{32}\b/', static function ($match) use (&$seen): string { if ($match[0] === str_repeat('a', 32)) { return $match[0]; } return $seen[$match[0]] ??= 'command-' . (count($seen) + 1); }, $encoded);
		return json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
	}

	private static function error(\Throwable $e): array {
		return ['class' => $e::class, 'message' => $e->getMessage(), 'code' => $e->getCode(), 'uncertain' => $e instanceof PolicyUnavailable ? $e->uncertain : null, 'previous' => $e->getPrevious() === null ? null : self::error($e->getPrevious())];
	}

	private static function deadlock(): \OCP\DB\Exception {
		return new class('deadlock') extends \OCP\DB\Exception {
			public function getReason(): ?int { return self::REASON_DEADLOCK; }
		};
	}
}
