<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Polls;

/** Counts are native aggregates only, after every received option proves the integer visibility flag. */
final class PollsResultShapes {
	/**
	 * Checks the whole native collection before serializing any masked aggregate or selecting a page.
	 * @param array<mixed> $options native filtered options
	 * @param int $pollId authorized poll
	 * @param callable():void $assert checked logged-in context
	 * @return list<array<string,mixed>> complete option/count projections
	 * @throws PollsRefusal for masked/unsupported/foreign data
	 */
	public function project(array $options, int $pollId, callable $assert): array {
		$class = PollsSurface::OPTION;
		foreach ($options as $option) {
			if (!$option instanceof $class) {
				throw PollsRefusal::unavailable();
			}
			$assert();
			if ($option->getShowResults() !== 1) {
				throw PollsRefusal::changed();
			}
		}
		$rows = (new PollsShapes())->options($options, $pollId, $assert);
		$counts = [];
		foreach ($options as $option) {
			$assert();
			if ($option->getShowResults() !== 1) {
				throw PollsRefusal::changed();
			}
			$assert();
			$row = $option->jsonSerialize();
			$native = $row['votes'] ?? null;
			if (!is_array($native)) {
				throw PollsRefusal::unavailable();
			}
			$count = [];
			foreach (['yes', 'no', 'maybe', 'count'] as $field) {
				$count[$field] = PollsContentValues::integer($native, $field);
			}
			$counts[PollsContentValues::integer($row, 'id', 1)] = $count;
		}
		foreach ($rows as &$row) {
			$row['votes'] = $counts[$row['id']];
		}
		unset($row);
		return $rows;
	}
}
