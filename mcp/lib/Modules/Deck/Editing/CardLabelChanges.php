<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Editing;

use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;

/** Canonical incremental changes and the scalar state a confirmation binds to. */
final class CardLabelChanges {
	public const MAX_LABELS = 100;

	/** Normalizes the incremental request without reading Deck.
	 * @param array<string, mixed> $arguments Validated tool arguments.
	 * @return array{add: list<int>, remove: list<int>} Sorted unique IDs.
	 * @throws ArgumentValidationException When the lists are invalid, empty or overlap.
	 */
	public static function parse(array $arguments): array {
		$out = [];
		foreach (['add', 'remove'] as $field) {
			$ids = $arguments[$field] ?? [];
			if (!is_array($ids) || !array_is_list($ids) || count($ids) > self::MAX_LABELS
				|| array_filter($ids, static fn ($id): bool => !is_int($id) || $id < 1) !== []) {
				throw new ArgumentValidationException('Invalid argument: ' . $field, $field, \OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::errorLabelIds());
			}
			$out[$field] = array_values(array_unique($ids));
			sort($out[$field]);
		}
		if (array_intersect($out['add'], $out['remove']) !== []) {
			throw new ArgumentValidationException('Invalid argument: add', 'add', \OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::errorLabelOverlap());
		}
		if ($out['add'] === [] && $out['remove'] === []) {
			throw new ArgumentValidationException('Invalid argument: add', 'add', \OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::errorLabelEmpty());
		}
		return $out;
	}

	/** Combines the IDs whose board membership must be checked.
	 * @param array{add: list<int>, remove: list<int>} $changes Normalized request.
	 * @return list<int> Sorted unique IDs.
	 */
	public static function requested(array $changes): array {
		$ids = array_values(array_unique([...$changes['add'], ...$changes['remove']]));
		sort($ids);
		return $ids;
	}

	/** Computes only the requested changes, preserving every other visible label.
	 * @param array<string, mixed> $state Fresh gateway state.
	 * @param array{add: list<int>, remove: list<int>} $changes Normalized request.
	 * @return array{add: list<int>, remove: list<int>, after: list<array>, unchanged: array} Actual delta.
	 */
	public static function delta(array $state, array $changes): array {
		$current = array_column($state['labels'], 'id');
		$add = array_values(array_diff($changes['add'], $current));
		$remove = array_values(array_intersect($changes['remove'], $current));
		$after = array_values(array_diff($current, $remove));
		$after = [...$after, ...$add];
		sort($after);
		$known = array_column([...$state['labels'], ...$state['requested']], null, 'id');
		return ['add' => $add, 'remove' => $remove,
			'after' => array_map(static fn (int $id): array => $known[$id], $after),
			'unchanged' => ['alreadyAssigned' => array_values(array_intersect($changes['add'], $current)),
				'notAssigned' => array_values(array_diff($changes['remove'], $current))]];
	}

	/** Selects the canonical state bound to a confirmation, including opaque inherited IDs.
	 * @param array<string, mixed> $state Fresh gateway state; foreign IDs never leave the HMAC.
	 * @return array<string, mixed> Scalar state for comparison and fingerprinting.
	 */
	public static function snapshot(array $state): array {
		$card = $state['card'];
		return ['card' => ['id' => (int)$card->getId(), 'title' => (string)$card->getTitle(),
			'stackId' => (int)$card->getStackId(), 'boardId' => $state['boardId']],
			'foreignAssigned' => $state['foreignAssigned'] ?? [],
			'board' => $state['board'], 'labels' => $state['labels'], 'requested' => $state['requested']];
	}
}
