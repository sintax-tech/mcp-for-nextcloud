<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\ResourceScope;

use OCA\Mcp\Access\Grants\Commands\UserAdmission;
use OCA\Mcp\Access\Policy\PolicyEventPayload;
use OCA\Mcp\Access\Policy\PolicyOutbox;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\ScopeInactive;
use OCA\Mcp\Access\Policy\ScopeItemsInaccessible;
use OCA\Mcp\Access\Policy\ScopeTooLarge;
use OCA\Mcp\Access\Policy\Transactions\PolicyExecution;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\ResourceScopeMapper;
use OCA\Mcp\Platform\Numbers\DecimalCounter;

/** Saves one user's resource scope on the shared policy transaction. */
final class ScopeWriter implements ScopeWriterContract {
	/**
	 * Composes this participant with the existing execution and scope services.
	 *
	 * @param PolicyExecution $execution Existing execution over the single transaction.
	 * @param UserAdmission $admission Official account admission.
	 * @param ResourceScopeMapper|null $scopes Resource scope storage, when available.
	 * @param NativeResourceEnumerator|null $resources Official resource access enumerator.
	 * @param ScopeActivation $activation Active resource families.
	 */
	public function __construct(
		private PolicyExecution $execution,
		private UserAdmission $admission,
		private ?ResourceScopeMapper $scopes,
		private ?NativeResourceEnumerator $resources,
		private ScopeActivation $activation,
	) {}
	/** {@inheritDoc} */
	public function setResourceScope(string $expectedRevision, string $actor, string $uid, string $module, ?array $items): array {
		if (!PolicyEventPayload::validUid($actor) || !in_array($module, ResourceScopeMapper::MODULES, true)) {
			throw new \InvalidArgumentException('Invalid command');
		}
		if (!$this->activation->isActive($module)) {
			throw new ScopeInactive('Resource scope is not active for this module');
		}
		$items = $items === null ? null : self::scopeItems($items);
		$scopes = $this->scopes;
		$resources = $this->resources;
		if ($scopes === null || $resources === null) {
			throw new PolicyUnavailable('Resource scope storage unavailable');
		}
		$receiptId = null;
		$result = $this->execution->runAdmin($expectedRevision, [$uid], function () use ($uid, $module, $items, $resources): void {
			$this->admission->assertUser($uid);
			$missing = $items === null ? [] : $resources->inaccessible($uid, $module, $items);
			if ($missing !== []) {
				usort($missing, DecimalCounter::compare(...));
				throw new ScopeItemsInaccessible($missing);
			}
		}, function ($snapshot, array $current, string $commandId, PolicyOutbox $outbox) use ($actor, $uid, $module, $items, &$receiptId): bool {
			$receiptId = null;
			$revision = PolicyExecution::next($current['revision']);
			$change = $this->writeScope($uid, $module, $items, $revision);
			if ($change === null) {
				return false;
			}
			$receiptId = $outbox->add(PolicyEventsMapper::KIND_RECEIPT, PolicyEventPayload::receipt($actor, PolicyEventPayload::ACTION_SCOPE, $current['revision'], $revision, [$change]));
			$outbox->add(PolicyEventsMapper::KIND_AUDIT, PolicyEventPayload::audit($actor, PolicyEventPayload::ACTION_SCOPE, $current['revision'], $revision, $receiptId, null, []));
			return true;
		});
		return ['revision' => $result['revision'], 'changed' => $result['changed'], 'receiptId' => $receiptId];
	}

	/**
	 * Replaces one scope under the mutex when it differs from the stored one, reading the stored scope in this same
	 * transaction (never a read made outside it).
	 *
	 * @param string $uid the user the scope belongs to
	 * @param string $module the resource family
	 * @param list<string>|null $items canonical ids in ascending order, or null for unrestricted
	 * @param string $revision the revision this commit produces, stored as policy_rev
	 * @return array{uid:string, key:string, before:?string, after:?string}|null the change, or null for a no-op
	 * @throws PolicyUnavailable when the stored scope cannot be read or verified
	 */
	public function writeScope(string $uid, string $module, ?array $items, string $revision): ?array {
		$scopes = $this->scopes ?? throw new PolicyUnavailable('Resource scope storage unavailable');
		try {
			$stored = $scopes->forModule($uid, $module);
			$before = PolicyEventPayload::scopeValue($stored === null ? null : $stored['items']);
			$after = PolicyEventPayload::scopeValue($items);
			if ($before === $after) {
				return null;
			}
			$scopes->replace($uid, $module, $items, $revision);
		} catch (\UnexpectedValueException $e) {
			throw new PolicyUnavailable('Invalid stored resource scope', previous: $e);
		}
		return ['uid' => $uid, 'key' => PolicyEventPayload::SCOPE_PREFIX . $module, 'before' => $before, 'after' => $after];
	}

	/**
	 * Sorts and checks the resource ids of one scope command.
	 *
	 * @param array<mixed> $items candidate ids
	 * @return list<string> the distinct ids, in ascending numeric order
	 * @throws \InvalidArgumentException for a non-list or an id that is not a positive canonical decimal string
	 * @throws ScopeTooLarge for more than ResourceScopeMapper::MAX_ITEMS distinct ids
	 */
	private static function scopeItems(array $items): array {
		if (!array_is_list($items)) {
			throw new \InvalidArgumentException('Invalid command');
		}
		$unique = [];
		foreach ($items as $id) {
			try {
				if (!is_string($id) || DecimalCounter::validate($id, DecimalCounter::MAX) === '0') {
					throw new \InvalidArgumentException('Invalid command');
				}
			} catch (\InvalidArgumentException $e) {
				throw new \InvalidArgumentException('Invalid command', 0, $e);
			}
			$unique[$id] = true;
			if (count($unique) > ResourceScopeMapper::MAX_ITEMS) {
				throw new ScopeTooLarge('Too many resources in one scope');
			}
		}
		$ids = array_map('strval', array_keys($unique));
		usort($ids, DecimalCounter::compare(...));
		return $ids;
	}
}
