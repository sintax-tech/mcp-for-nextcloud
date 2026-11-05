<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Receipts;

use Closure;
use OCA\Mcp\Access\Grants\Commands\CellWriter;
use OCA\Mcp\Access\Policy\PolicyConflict;
use OCA\Mcp\Access\Policy\PolicyEventPayload;
use OCA\Mcp\Access\Policy\PolicyOutbox;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\ReceiptExpired;
use OCA\Mcp\Access\Policy\ReceiptNotFound;
use OCA\Mcp\Access\Policy\ScopeInactive;
use OCA\Mcp\Access\Policy\Transactions\PolicyExecution;
use OCA\Mcp\Access\ResourceScope\ScopeActivation;
use OCA\Mcp\Access\ResourceScope\ScopeWriter;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Platform\Numbers\DecimalCounter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DatabaseException;
use OCP\IUserManager;

/** Undoes one stored receipt on the shared policy transaction. */
final class ReceiptRestorer implements ReceiptRestorerContract {
	/** Seconds an administrator may undo a command through its receipt (contracts §2.3). */
	public const RECEIPT_TTL = 600;

	/**
	 * Composes this participant with the existing execution and scope writer.
	 *
	 * @param PolicyExecution $execution Existing execution over the single transaction.
	 * @param IUserManager $users Official account directory.
	 * @param PolicyEventsMapper $events Transactional event mapper.
	 * @param ITimeFactory $time Official clock.
	 * @param ScopeActivation $activation Active resource families.
	 * @param CellWriter $cells Shared raw cell writes.
	 * @param ScopeWriter $scopeWriter Shared scope writes.
	 */
	public function __construct(
		private PolicyExecution $execution,
		private IUserManager $users,
		private PolicyEventsMapper $events,
		private ITimeFactory $time,
		private ScopeActivation $activation,
		private CellWriter $cells,
		private ScopeWriter $scopeWriter,
	) {}
	/** {@inheritDoc} */
	public function restoreReceipt(string $receiptId, string $expectedRevision, string $actor, ?Closure $inheritance = null): array {
		DecimalCounter::validate($expectedRevision);
		$receipt = $this->receipt($receiptId, $actor);
		if ($receipt['revision'] !== $expectedRevision) {
			throw new PolicyConflict();
		}
		$cells = array_map(static fn (array $change): array => ['uid' => $change['uid'], 'key' => $change['key'], 'value' => $change['before']], $receipt['changes']);
		$uids = array_values(array_unique(array_column($cells, 'uid')));
		// Scope cells go back to the scope tables, grant cells to the preferences.
		$scopeCells = array_values(array_filter($cells, static fn (array $cell): bool => PolicyEventPayload::scopeModule($cell['key']) !== null));
		$grantCells = array_values(array_filter($cells, static fn (array $cell): bool => PolicyEventPayload::scopeModule($cell['key']) === null));
		foreach ($scopeCells as $cell) {
			if (!$this->activation->isActive((string)PolicyEventPayload::scopeModule($cell['key']))) {
				throw new ScopeInactive('Resource scope is not active for this module');
			}
		}
		$changed = 0;
		$result = $this->execution->runAdmin($expectedRevision, $uids, function () use ($uids, $grantCells, $inheritance): array {
			foreach ($uids as $uid) {
				if (!PolicyEventPayload::validUid($uid) || $this->users->get($uid) === null) {
					// Writing preferences back for a deleted account would leave orphans; the state moved on.
					throw new PolicyConflict();
				}
			}
			return $this->cells->inheritances($grantCells, $inheritance);
		}, function (array $inherited, array $current, string $commandId, PolicyOutbox $outbox) use ($grantCells, $scopeCells, $actor, $receiptId, &$changed): bool {
			$changes = $grantCells === [] ? [] : $this->cells->applyLocal($grantCells, $inherited);
			$revision = PolicyExecution::next($current['revision']);
			foreach ($scopeCells as $cell) {
				$items = PolicyEventPayload::scopeItems($cell['value']);
				$change = $items === false ? throw new PolicyUnavailable('Invalid stored receipt')
					: $this->scopeWriter->writeScope($cell['uid'], (string)PolicyEventPayload::scopeModule($cell['key']), $items, $revision);
				if ($change !== null) {
					$changes[] = $change;
				}
			}
			$changed = count($changes);
			if ($changes === []) {
				return false;
			}
			$outbox->add(PolicyEventsMapper::KIND_AUDIT, PolicyEventPayload::audit($actor, PolicyEventPayload::ACTION_UNDO,
				$current['revision'], PolicyExecution::next($current['revision']), null, $receiptId, $changes));
			return true;
		});
		return ['revision' => $result['revision'], 'changed' => $changed];
	}

	/** {@inheritDoc} */
	public function receiptUsers(string $receiptId, string $actor): ?array {
		try {
			$receipt = $this->receipt($receiptId, $actor, false);
		} catch (ReceiptNotFound|PolicyUnavailable) {
			return null;
		}
		return array_values(array_unique(array_column($receipt['changes'], 'uid')));
	}

	/**
	 * Loads a receipt for its own administrator, checking ownership before expiry so another administrator's
	 * receipt is indistinguishable from an unknown one.
	 *
	 * @param bool $live whether an expired receipt is refused (false only to describe it)
	 * @return array{revision:string, changes:list<array{uid:string, key:string, before:?string, after:?string}>}
	 * @throws ReceiptNotFound|ReceiptExpired|PolicyUnavailable
	 */
	private function receipt(string $receiptId, string $actor, bool $live = true): array {
		if (!PolicyEventsMapper::validId($receiptId)) {
			throw new ReceiptNotFound();
		}
		try {
			$event = $this->events->find($receiptId);
			$payload = $event === null || $event['kind'] !== PolicyEventsMapper::KIND_RECEIPT ? null
				: PolicyEventPayload::validate(PolicyEventsMapper::KIND_RECEIPT, $event['payload']);
		} catch (\UnexpectedValueException $e) {
			throw new PolicyUnavailable('Invalid stored receipt', previous: $e);
		} catch (DatabaseException $e) {
			throw new PolicyUnavailable(previous: $e);
		}
		if ($payload === null || $payload['actor'] !== $actor) {
			throw new ReceiptNotFound();
		}
		if ($live && $this->time->getTime() >= $event['createdAt'] + ReceiptRestorer::RECEIPT_TTL) {
			throw new ReceiptExpired();
		}
		return ['revision' => $payload['revision'], 'changes' => $payload['changes']];
	}
}
