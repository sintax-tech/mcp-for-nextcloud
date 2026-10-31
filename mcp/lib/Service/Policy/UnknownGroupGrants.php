<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service\Policy;

use OCA\Mcp\Db\GroupGrantRows;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCA\Mcp\Db\StoredGroupGrant;

/** Administrative diagnosis and explicit cleanup, independent of directory/resolver availability. */
final class UnknownGroupGrants {
	/**
	 * @param GroupGrantRows $rows Validated row reader and exact-row mutation.
	 * @param PolicyStateMapper $state Global policy revision.
	 * @param PolicyWriter $writer Transaction, mutex, CAS and transactional audit outbox.
	 */
	public function __construct(private GroupGrantRows $rows, private PolicyStateMapper $state, private PolicyWriter $writer) {}

	/**
	 * @param string $gid Exact group identity.
	 * @return array Group identity, unknown rows, opaque snapshot and paired revision.
	 * @throws PolicyUnavailable Corrupt storage or concurrent writes prevent a consistent diagnosis.
	 */
	public function report(string $gid): array {
		try {
			for ($attempt = 0; $attempt < 2; ++$attempt) {
				$revision = $this->state->state()['revision'];
				$policy = $this->rows->read([$gid])[0];
				if ($revision === $this->state->state()['revision']) {
					return ['groupId' => $gid, 'unknownGrants' => $policy['unknownGrants'] ?? [],
						'grantSnapshot' => $policy['grantSnapshot'] ?? null, 'revision' => $revision];
				}
			}
		} catch (\UnexpectedValueException|\OCP\DB\Exception $e) {
			throw new PolicyUnavailable('Cannot read group grants', previous: $e);
		}
		throw new PolicyUnavailable('Group grants changed during diagnosis');
	}

	/**
	 * Removes only selected rows still unknown in the current catalog, never free client-supplied keys.
	 * The before/after snapshots, actor and timestamp are stored atomically in the existing audit outbox.
	 *
	 * @param string $expectedRevision Revision paired with the displayed report.
	 * @param string $actor Authenticated administrator identity.
	 * @param string $gid Exact group identity.
	 * @param mixed $ids Non-empty list of opaque row IDs from the report.
	 * @param mixed $snapshot Opaque fingerprint of all displayed group rows.
	 * @param mixed $confirm Must be the boolean true.
	 * @return array Revision, audit event ID, removed count, delivery state and fresh diagnosis.
	 * @throws \InvalidArgumentException Invalid input or absent explicit confirmation.
	 * @throws PolicyConflict Stale revision, changed rows or a selected grant now known.
	 * @throws PolicyUnavailable Storage failure or an uncertain post-commit readback.
	 */
	public function clear(string $expectedRevision, string $actor, string $gid, mixed $ids, mixed $snapshot, mixed $confirm): array {
		if ($confirm !== true || !PolicyEventPayload::validUid($actor) || !PolicyEventPayload::validUid($gid)
			|| !is_array($ids) || !array_is_list($ids) || $ids === [] || count($ids) > 200
			|| !is_string($snapshot) || preg_match('/^[a-f0-9]{64}$/D', $snapshot) !== 1) {
			throw new \InvalidArgumentException('Invalid cleanup');
		}
		foreach ($ids as $id) {
			if (!is_string($id) || preg_match('/^[a-f0-9]{64}$/D', $id) !== 1) {
				throw new \InvalidArgumentException('Invalid cleanup selection');
			}
		}
		if (count(array_unique($ids)) !== count($ids)) {
			throw new \InvalidArgumentException('Duplicate cleanup selection');
		}
		$auditId = null;
		$result = $this->writer->runAdmin($expectedRevision, [], static fn () => null,
			function ($prepared, array $current, string $commandId, PolicyOutbox $outbox) use ($actor, $gid, $ids, $snapshot, &$auditId): bool {
				try {
					$before = $this->rows->read([$gid])[0];
					if (($before['grantSnapshot'] ?? null) !== $snapshot) {
						throw new PolicyConflict();
					}
					$selected = array_values(array_filter($before['unknownGrants'] ?? [], static fn (array $row): bool => in_array($row['id'], $ids, true)));
					if (count($selected) !== count($ids)) {
						throw new PolicyConflict();
					}
					foreach ($selected as $row) {
						if (StoredGroupGrant::known($row['module'], $row['operation']) || $this->rows->remove($gid, $row['module'], $row['operation']) !== 1) {
							throw new PolicyConflict();
						}
					}
					$after = $this->rows->read([$gid])[0];
				} catch (\UnexpectedValueException $e) {
					throw new PolicyUnavailable('Cannot clean group grants', previous: $e);
				}
				$auditId = $outbox->add(PolicyEventsMapper::KIND_AUDIT, PolicyEventPayload::audit($actor, PolicyEventPayload::ACTION_GROUP,
					$current['revision'], PolicyWriter::next($current['revision']), null, null, [['gid' => $gid, 'key' => PolicyEventPayload::GROUP_KEY,
						'before' => json_encode(['unknownCleanup' => true, 'policy' => $before], JSON_THROW_ON_ERROR),
						'after' => json_encode(['unknownCleanup' => true, 'policy' => $after], JSON_THROW_ON_ERROR)]]));
				return true;
			});
		try {
			$report = $this->report($gid);
		} catch (\Throwable $e) {
			throw new PolicyUnavailable('Cleanup committed but readback failed', true, $e);
		}
		return ['revision' => $result['revision'], 'auditId' => $auditId, 'removed' => count($ids),
			'auditPending' => $result['cleanupPending'], 'report' => $report];
	}
}
