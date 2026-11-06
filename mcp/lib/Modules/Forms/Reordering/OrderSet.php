<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Reordering;

use OCA\Mcp\Modules\Forms\Editing\EditValues;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\FileAccess\CommonMessages;

/** Complete-set ordering shared by question and option reorder: validation, native order prediction and readback. */
final class OrderSet {
	private function __construct() {
	}

	/**
	 * @param list<array<string,mixed>> $items the current partition, each with id and order
	 * @param list<int> $ids requested order
	 * @param string $field argument name
	 * @param list<int> $scope every ID of the parent object; an ID outside it is answered as not found
	 * @return array<string,int> predicted native order (position + 1), keyed by ID
	 */
	public static function complete(array $items, array $ids, string $field, array $scope = []): array {
		$current = array_column($items, 'id');
		$known = $scope === [] ? $current : $scope;
		foreach ($ids as $id) {
			if (!in_array($id, $known, true)) {
				throw new ToolFailure(CommonMessages::notFound());
			}
		}
		$sorted = $ids;
		sort($sorted);
		sort($current);
		if ($sorted !== $current) {
			EditValues::invalid($field);
		}
		$expected = [];
		foreach ($ids as $index => $id) {
			$expected[(string)$id] = $index + 1;
		}
		return $expected;
	}

	/**
	 * @param list<array<string,mixed>> $items current partition
	 * @param array<string,int> $expected predicted orders
	 * @return bool whether native would write nothing (every order already equals its position)
	 */
	public static function unchanged(array $items, array $expected): bool {
		foreach ($items as $item) {
			if ($item['order'] !== $expected[(string)$item['id']]) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param list<array<string,mixed>> $items partition read after the attempt
	 * @param list<array<string,mixed>> $before partition the plan showed
	 * @param array<string,int> $expected predicted orders
	 * @return array{observed: mixed, complete: bool, changed: bool} observed orders
	 */
	public static function observe(array $items, array $before, array $expected): array {
		$observed = [];
		foreach ($items as $item) {
			if (array_key_exists((string)$item['id'], $expected)) {
				$observed[(string)$item['id']] = $item['order'];
			}
		}
		$changed = false;
		foreach ($before as $item) {
			$changed = $changed || ($observed[(string)$item['id']] ?? null) !== $item['order'];
		}
		ksort($observed);
		ksort($expected);
		return ['observed' => ['orders' => $observed], 'complete' => $observed === $expected, 'changed' => $changed];
	}
}
