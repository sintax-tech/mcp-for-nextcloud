<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Integration;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Tables\Presentation\SchemaMessages;

/** Materializes nested native JsonSerializable settings without altering their values or losing flags. */
final class SchemaNativeSnapshot {
	/**
	 * @param object $entity Authorized native entity.
	 * @return array<string,mixed> Plain native snapshot.
	 */
	public static function of(object $entity): array { return self::plain($entity->jsonSerialize(), 0); }
	/**
	 * @param mixed $value Native metadata.
	 * @param int $depth Structural bound.
	 * @return mixed Plain scalar/list/map.
	 */
	private static function plain(mixed $value, int $depth): mixed {
		if ($depth > 12) throw new ToolFailure(SchemaMessages::incompatible());
		if ($value instanceof \JsonSerializable) $value = $value->jsonSerialize();
		if (!is_array($value)) return $value;
		foreach ($value as &$item) $item = self::plain($item, $depth + 1);
		return $value;
	}
}
