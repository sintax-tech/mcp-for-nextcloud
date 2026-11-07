<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Options;

use OCA\Mcp\Modules\Polls\Writing\PollWriteRequest;
use OCA\Mcp\Modules\Polls\Presentation\PollsWriteMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\FileAccess\CommonMessages;

/** Option membership, complete ordering, collision disclosure and readback comparisons. */
final class OptionChanges {
	/**
	 * @param array<string,mixed> $r Request.
	 * @param array<string,mixed> $s Snapshot.
	 * @return array<string,mixed> Desired option effect.
	 * @throws \OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException If a field or requested transition is invalid.
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure If a target or effective sharing policy refuses the operation.
	 */
	public static function effect(array $r, array $s): array {
		$rows = $s['options'];
		$notes = [PollsWriteMessages::options()];
		if (isset($r['option'])) {
			if (($s['poll']['type'] === 'textPoll') !== isset($r['option']['text'])) {
				PollWriteRequest::invalid('option');
			}
			$value = $s['optionValue'];
			if ($r['tool'] === 'polls_add_option') {
				$matches = array_values(array_filter($rows, static fn(array $row): bool => self::value($row) === $value));
				if (count($matches) > 1) {
					PollWriteRequest::invalid('option');
				}
				$old = $matches[0] ?? null;
				$order = $old['order'] ?? ($s['poll']['type'] === 'datePoll'
					? version_compare($s['version'],
					'9.0.0',
					'<')
					? null
					: $value['timestamp']
					: 1 + max([0,
					...array_column($rows,
					'order')]));
				$after = $value + [
					'id' => $old['id'] ?? null,
					'order' => $order,
					'deleted' => false,
					'owner' => $old['owner'] ?? ($s['poll']['owner'] === $s['actor'] ? '' : $s['actor']),
				];
				return ['before' => $old, 'after' => $after, 'noop' => $old !== null && !$old['deleted'], 'notes' => $notes];
			}
			$old = self::find($rows, $r['option_id']);
			if ($old['deleted'] || ($s['optionCollision'] ?? false)) {
				PollWriteRequest::invalid('option_id');
			}
			$after = array_replace($old, $value);
			if ($s['poll']['type'] === 'datePoll') {
				$after['order'] = $value['timestamp'];
			}
			return ['before' => $old, 'after' => $after, 'noop' => $old === $after, 'notes' => $notes];
		}
		if (isset($r['option_ids'])) {
			$active = array_values(array_filter($rows, static fn(array $row): bool => !$row['deleted']));
			if ($s['poll']['type'] !== 'textPoll' || $s['poll']['expire'] > 0 && $s['poll']['expire'] < time()) {
				PollWriteRequest::invalid('option_ids');
			}
			$ids = array_column($active, 'id');
			$wanted = $r['option_ids'];
			sort($ids);
			sort($wanted);
			if ($ids !== $wanted) {
				PollWriteRequest::invalid('option_ids');
			}
			$after = [];
			foreach ($r['option_ids'] as $i => $id) {
				$after[] = array_replace(self::find($active, $id), ['order' => $i + 1]);
			}
			usort($active, static fn(array $a, array $b): int => [$a['order'], $a['id']] <=> [$b['order'], $b['id']]);
			return ['before' => $active, 'after' => $after, 'noop' => $active === $after, 'notes' => $notes];
		}
		$old = self::find($rows, $r['option_id']);
		$after = array_replace($old, ['deleted' => $r['tool'] === 'polls_remove_option']);
		return [
			'before' => $old,
			'after' => $after,
			'noop' => $old === $after,
			'notes' => [...$notes, PollsWriteMessages::optionRemoval()],
		];
	}

	/**
	 * @param list<array<string,mixed>> $rows Authorized rows.
	 * @param int $id Child ID.
	 * @return array<string,mixed> Row in this poll only.
	 * @throws \OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException If a field or requested transition is invalid.
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure If a target or effective sharing policy refuses the operation.
	 */
	public static function find(array $rows, int $id): array {
		foreach ($rows as $row) {
			if ($row['id'] === $id) {
				return $row;
			}
		}
		throw new ToolFailure(CommonMessages::notFound());
	}

	/**
	 * @param array<string,mixed> $row Option projection.
	 * @return array<string,mixed> Canonical native value.
	 * @throws \OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException If a field or requested transition is invalid.
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure If a target or effective sharing policy refuses the operation.
	 */
	private static function value(array $row): array {
		return array_intersect_key($row, array_flip(['text', 'timestamp', 'duration']));
	}

	/**
	 * @param array<string,mixed> $r Request.
	 * @param array<string,mixed> $before Approved state.
	 * @param array<string,mixed> $now Fresh state.
	 * @return bool Full requested effect observed.
	 * @throws \OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException If a field or requested transition is invalid.
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure If a target or effective sharing policy refuses the operation.
	 */
	public static function matches(array $r, array $before, array $now): bool {
		$after = self::effect($r, $before)['after'];
		if (isset($r['option_ids'])) {
			$active = array_values(array_filter($now['options'], static fn(array $row): bool => !$row['deleted']));
			usort($active, static fn(array $a, array $b): int => [$a['order'], $a['id']] <=> [$b['order'], $b['id']]);
			return $after === $active;
		}
		$rows = $now['options'];
		if ($r['tool'] === 'polls_add_option') {
			$rows = array_values(array_filter($rows, static fn(array $row): bool => self::value($row) === self::value($after)));
		} else {
			$rows = array_values(array_filter($rows, static fn(array $row): bool => $row['id'] === $r['option_id']));
		}
		if (count($rows) !== 1) {
			return false;
		}
		foreach ($after as $key => $value) {
			if ($value !== null && $rows[0][$key] !== $value) {
				return false;
			}
		}
		return true;
	}
}
