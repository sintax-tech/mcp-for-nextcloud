<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Db;

use InvalidArgumentException;
use OCP\IDBConnection;
use UnexpectedValueException;

/** Reads canonical grants and isolates well-formed opaque rows without granting them. */
final class GroupGrantRows {
	/** @param IDBConnection $db App-owned storage connection. */
	public function __construct(private IDBConnection $db) {}

	/**
	 * @param list<string> $groupIds Exact group identities.
	 * @return list<array> Known grants plus administrative diagnostics when unknown rows exist.
	 * @throws InvalidArgumentException Invalid identities.
	 * @throws UnexpectedValueException Corrupt, duplicate or ambiguous rows.
	 */
	public function read(array $groupIds): array {
		if (!array_is_list($groupIds)) {
			throw new InvalidArgumentException('Group ids must be a list');
		}
		$groups = [];
		$unknown = [];
		$seen = [];
		foreach ($groupIds as $gid) {
			if (!is_string($gid) || $gid === '' || str_contains($gid, "\0") || !mb_check_encoding($gid, 'UTF-8')) {
				throw new InvalidArgumentException('Invalid group id');
			}
			$key = hash('sha256', "group\0" . $gid);
			if (isset($groups[$key]) && $groups[$key]['groupId'] !== $gid) {
				throw new UnexpectedValueException('Group identity collision');
			}
			$groups[$key] = ['groupId' => $gid, 'grants' => []];
		}
		foreach (array_chunk($groups, 200, true) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$keys = array_map(fn (string $key) => $qb->createNamedParameter($key), array_keys($chunk));
			$result = $qb->select('group_key', 'group_id', 'module', 'operation')->from('mcp_group_grants')
				->where($qb->expr()->in('group_key', $keys))->executeQuery();
			try {
				while (($row = $result->fetch()) !== false) {
					$key = $row['group_key'] ?? null;
					$gid = $row['group_id'] ?? null;
					$module = $row['module'] ?? null;
					$operation = $row['operation'] ?? null;
					if (!is_string($key) || !isset($chunk[$key]) || $gid !== $chunk[$key]['groupId']
						|| !is_string($module) || !is_string($operation)
						|| !StoredGroupGrant::wellFormed($module, $operation)
						|| isset($seen[$key][StoredGroupGrant::id($gid, $module, $operation)])) {
						throw new UnexpectedValueException('Invalid stored group grant');
					}
					$grant = new StoredGroupGrant($gid, $module, $operation);
					$id = $grant->selectionId;
					$seen[$key][$id] = true;
					if ($grant->recognized) {
						$groups[$key]['grants'][$module][] = $operation;
					} else {
						$unknown[$key][] = $grant->diagnostic();
					}
				}
			} finally {
				$result->closeCursor();
			}
		}
		foreach ($groups as $key => &$group) {
			ksort($group['grants'], SORT_STRING);
			foreach ($group['grants'] as &$operations) {
				sort($operations, SORT_STRING);
			}
			unset($operations);
			if (isset($unknown[$key])) {
				usort($unknown[$key], static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
				$group['unknownGrants'] = $unknown[$key];
				$group['grantSnapshot'] = hash('sha256', json_encode($group, JSON_THROW_ON_ERROR));
			}
		}
		unset($group);
		return array_values($groups);
	}

	/**
	 * Removes one exact stored row; only callers under the policy mutex may mutate.
	 * @param string $gid Exact group identity.
	 * @param string $module Stored module.
	 * @param string $operation Stored operation.
	 * @return int Number of rows removed.
	 * @throws \LogicException Outside a transaction.
	 */
	public function remove(string $gid, string $module, string $operation): int {
		if (!$this->db->inTransaction()) {
			throw new \LogicException('Group grants require a policy transaction');
		}
		$qb = $this->db->getQueryBuilder();
		return $qb->delete('mcp_group_grants')
			->where($qb->expr()->eq('group_key', $qb->createNamedParameter(hash('sha256', "group\0" . $gid))))
			->andWhere($qb->expr()->eq('group_id', $qb->createNamedParameter($gid)))
			->andWhere($qb->expr()->eq('module', $qb->createNamedParameter($module)))
			->andWhere($qb->expr()->eq('operation', $qb->createNamedParameter($operation)))->executeStatement();
	}

	/**
	 * Normal policy replacement and lifecycle cleanup preserve unknown rows for explicit admin cleanup.
	 * @param string $gid Exact group identity.
	 * @return int Known rows removed.
	 * @throws UnexpectedValueException Corrupt storage, before any removal.
	 */
	public function removeKnown(string $gid): int {
		$policy = $this->read([$gid])[0];
		$count = 0;
		foreach ($policy['grants'] as $module => $operations) {
			foreach ($operations as $operation) {
				$count += $this->remove($gid, $module, $operation);
			}
		}
		return $count;
	}
}
