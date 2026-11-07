<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Presentation;

use OCA\Mcp\Modules\Tables\Writing\SchemaReceiptComparison;

/** Includes native layout/card metadata only as preserved plan and readback state, never as writable arguments. */
final class SchemaViewSnapshot {
	/**
	 * @param list<string> $keys Existing authorized snapshot keys.
	 * @return list<string> Snapshot keys including the two additive native view fields.
	 */
	public static function keys(array $keys): array {
		return [...$keys, 'columns', 'columnSettings', 'filter', 'sort', 'layout', 'viewSettings'];
	}

	/**
	 * @param array<string,mixed> $before Authorized original view snapshot.
	 * @param array<string,mixed> $observed Fresh authorized readback.
	 * @return bool Every previously present layout/card setting stayed unchanged.
	 */
	public static function preserved(array $before, array $observed): bool {
		foreach (['layout', 'viewSettings'] as $key) {
			if (array_key_exists($key, $before) && (!array_key_exists($key, $observed)
				|| !SchemaReceiptComparison::same($before[$key], $observed[$key]))) return false;
		}
		return true;
	}
}
