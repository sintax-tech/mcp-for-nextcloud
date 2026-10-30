<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables\Rows;

/**
 * The search as native conditions on the columns the user can see, OR-ed: `contains` on every text column, and for the
 * single and multiple selection columns the ids of the options whose label contains the term (labels and term
 * normalized to NFC, then lower-cased), as `is-equal <id>` on a single selection and `contains <id>` on a multiple one,
 * which the engine matches as a whole id ([id], [id,…], …,id] or …,id,…), so 1 never matches 10. The engine stores ids,
 * not labels, so a label is only found through this translation. A search with no candidate anywhere is FALSE (nothing
 * matches), never an empty filter. Text case follows the engine: `like` up to 2.2.x, by the database collation, and
 * `iLike` from 2.3.
 */
final class RowSearch {
	/** Most conditions the search may expand into. */
	public const MAX_CONDITIONS = 32;

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The search as one OR group per candidate.
	 *
	 * @param string|null $term the search, null when absent
	 * @param list<array<string, mixed>> $projection the serialized columns the user can see
	 * @return RowCondition TRUE without a search, FALSE without any candidate, else one group per candidate
	 * @throws RowsRefusal when the search expands into more conditions than allowed
	 */
	public static function of(?string $term, array $projection): RowCondition {
		if ($term === null) {
			return RowCondition::true();
		}
		$needle = self::normalize($term);
		$groups = [];
		foreach ($projection as $column) {
			$id = (int)$column['id'];
			$type = (string)($column['type'] ?? '');
			$subtype = RowFilterDomain::subtype($column);
			if ($type === 'text') {
				$groups[] = [['columnId' => $id, 'operator' => 'contains', 'value' => $term]];
			} elseif ($type === 'selection' && in_array($subtype, ['', 'multi'], true)) {
				foreach ((array)($column['selectionOptions'] ?? []) as $option) {
					if (is_array($option) && isset($option['id']) && str_contains(self::normalize((string)($option['label'] ?? '')), $needle)) {
						$groups[] = [['columnId' => $id, 'operator' => $subtype === 'multi' ? 'contains' : 'is-equal', 'value' => (string)(int)$option['id']]];
					}
				}
			}
			if (count($groups) > self::MAX_CONDITIONS) {
				throw RowsRefusal::tooLarge();
			}
		}
		return $groups === [] ? RowCondition::false() : RowCondition::of($groups);
	}

	/**
	 * A text normalized for matching labels: NFC, then lower case.
	 *
	 * @param string $text the text
	 * @return string the normalized text
	 */
	public static function normalize(string $text): string {
		$composed = class_exists(\Normalizer::class) ? \Normalizer::normalize($text, \Normalizer::FORM_C) : $text;
		return mb_strtolower(is_string($composed) ? $composed : $text, 'UTF-8');
	}
}
