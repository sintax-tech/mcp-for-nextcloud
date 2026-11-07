<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Grants\Commands;

use Closure;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\PolicyEventPayload;
use OCA\Mcp\Access\Policy\PolicyOutbox;
use OCA\Mcp\Access\Policy\Transactions\PolicyExecution;
use OCA\Mcp\Db\PolicyEventsMapper;

/** Individual grants and batched administrative cell commands. */
final class GrantCommands implements GrantCommandsContract {
	/** Most distinct user cells in one administrative command. */
	public const MAX_CELLS = 2500;

	/**
	 * Composes this participant with the existing official services.
	 *
	 * @param PolicyExecution $execution Existing execution over the single transaction.
	 * @param CellWriter $cells Shared raw cell writes.
	 * @param UserAdmission $users Official account admission.
	 */
	public function __construct(
		private PolicyExecution $execution,
		private CellWriter $cells,
		private UserAdmission $users,
	) {}

	/** {@inheritDoc} */
	public function setEligible(string $uid, bool $enabled): void {
		$this->cells->change($uid, GrantPolicy::ELIGIBLE_KEY, $enabled ? '1' : '0');
	}

	/** {@inheritDoc} */
	public function setConnected(string $uid, bool $enabled): void {
		$this->cells->change($uid, GrantPolicy::CONNECTED_KEY, $enabled ? '1' : '0');
	}

	/** {@inheritDoc} */
	public function setGrant(string $uid, string $module, string $operation, bool $enabled): void {
		if (!GrantPolicy::inCatalog($module, $operation)) {
			throw new \InvalidArgumentException('Unknown module or operation');
		}
		$this->cells->change($uid, 'grant_' . $module . '_' . $operation, $enabled ? '1' : '0');
	}

	/** {@inheritDoc} */
	public function applyCells(string $expectedRevision, string $actor, string $action, array $cells, ?Closure $inheritance = null): array {
		if (!in_array($action, PolicyEventPayload::GRANT_ACTIONS, true) || !PolicyEventPayload::validUid($actor)) {
			throw new \InvalidArgumentException('Invalid command');
		}
		$cells = self::cells($cells);
		$uids = array_values(array_unique(array_column($cells, 'uid')));
		$receiptId = null;
		$changed = 0;
		$result = $this->execution->runAdmin($expectedRevision, $uids, function () use ($uids, $cells, $inheritance): array {
			foreach ($uids as $uid) {
				$this->users->assertUser($uid);
			}
			return $this->cells->inheritances($cells, $inheritance);
		}, function (array $inherited, array $current, string $commandId, PolicyOutbox $outbox) use ($cells, $actor, $action, &$receiptId, &$changed): bool {
			$changes = $this->cells->applyLocal($cells, $inherited);
			$changed = count($changes);
			$receiptId = null;
			if ($changes === []) {
				return false;
			}
			$revision = PolicyExecution::next($current['revision']);
			$receiptId = $outbox->add(PolicyEventsMapper::KIND_RECEIPT, PolicyEventPayload::receipt($actor, $action, $current['revision'], $revision, $changes));
			$outbox->add(PolicyEventsMapper::KIND_AUDIT, PolicyEventPayload::audit($actor, $action, $current['revision'], $revision, $receiptId, null, []));
			return true;
		});
		return ['revision' => $result['revision'], 'changed' => $changed, 'receiptId' => $receiptId];
	}

	/**
	 * Validates a whole command before anything runs: shapes, keys, values and limits.
	 *
	 * @param array<mixed> $cells candidate cells
	 * @return list<array{uid:string, key:string, value:?string}> the distinct cells, in first-seen order
	 * @throws \InvalidArgumentException for any invalid cell, a cell asked with two values, or a limit exceeded
	 */
	private static function cells(array $cells): array {
		if ($cells === [] || !array_is_list($cells)) {
			throw new \InvalidArgumentException('Invalid command');
		}
		$distinct = [];
		$uids = [];
		foreach ($cells as $cell) {
			if (!is_array($cell) || !is_string($cell['uid'] ?? null) || !is_string($cell['key'] ?? null)
				|| !array_key_exists('value', $cell) || !in_array($cell['value'], ['1', '0', null], true)
				|| !PolicyEventPayload::validUid($cell['uid']) || !PolicyEventPayload::validUserKey($cell['key'])) {
				throw new \InvalidArgumentException('Invalid command');
			}
			$id = hash('sha256', $cell['uid'] . "\0" . $cell['key']);
			if (array_key_exists($id, $distinct)) {
				if ($distinct[$id]['value'] !== $cell['value']) {
					throw new \InvalidArgumentException('Invalid command');
				}
				continue;
			}
			$distinct[$id] = ['uid' => $cell['uid'], 'key' => $cell['key'], 'value' => $cell['value']];
			$uids[$cell['uid']] = true;
			if (count($distinct) > self::MAX_CELLS || count($uids) > PolicyExecution::MAX_UIDS) {
				throw new \InvalidArgumentException('Invalid command');
			}
		}
		return array_values($distinct);
	}
}
