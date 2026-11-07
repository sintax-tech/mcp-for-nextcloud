<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Writing;

/** Refuses implicit native card-source cleanup; the MCP schema exposes no layout or card-setting writes. */
final class SchemaViewPreservation {
	/**
	 * @param array<string,mixed> $patch Validated native-key patch.
	 * @param array<string,mixed> $before Authorized native snapshot.
	 * @param array<int,array<string,mixed>> $columns Columns returned by the native managed-view lookup.
	 * @param string $profile Proven native API.
	 * @return array<string,mixed> Unchanged patch when every native card source is preserved.
	 */
	public static function check(array $patch, array $before, array $columns, string $profile): array {
		if ($profile !== '2.4.0' || !array_key_exists('columnSettings', $patch)) return $patch;
		$ids = array_column($patch['columnSettings'], 'columnId');
		// The native update falls back to managed columns when the explicit selection is empty.
		if ($ids === []) $ids = array_keys($columns);
		self::requireSources($before, $ids, 'patch.columns');
		return $patch;
	}

	/**
	 * @param array<string,mixed> $view Authorized native view snapshot.
	 * @param string $profile Proven API.
	 * @return array<string,mixed> Unchanged snapshot if adding an explicit column preserves all card sources.
	 */
	public static function columnAdded(array $view, string $profile): array {
		if ($profile === '2.4.0') {
			// Native addColumnToView turns even an empty/all-columns selection into an explicit old+new list.
			// The newly allocated ID cannot preserve an existing card source omitted from the old explicit list.
			self::requireSources($view, array_column($view['columnSettings'] ?? [], 'columnId'), 'view_ids');
		}
		return $view;
	}

	/**
	 * @param array<string,mixed> $before Native card settings.
	 * @param list<int> $ids Columns the native update would retain.
	 * @param string $field Client field responsible for the implicit cleanup.
	 * @return void Refuse any effect that would erase an existing source.
	 */
	private static function requireSources(array $before, array $ids, string $field): void {
		foreach (['cardBackgroundSource', 'cardTitleSource'] as $key) {
			$source = $before['viewSettings'][$key] ?? null;
			if ($source !== null && (!is_int($source) || !in_array($source, $ids, true))) SchemaRequest::invalid($field);
		}
	}
}
