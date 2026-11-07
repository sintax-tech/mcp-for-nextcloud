<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Integration;

/** Requires additive native view fields before the 2.4.0 profile may preserve them. */
final class SchemaViewShape {
	/**
	 * @param list<array> $members Existing exact native signatures and fields.
	 * @param string $profile Evidence-bounded native profile.
	 * @return list<array> Complete member inventory for that profile.
	 */
	public static function members(array $members, string $profile): array {
		if ($profile === '2.4.0') {
			foreach (['layout', 'viewSettings'] as $field) $members[] = ['OCA\\Tables\\Db\\View', '@' . $field, [], ''];
		}
		return $members;
	}
	/**
	 * @param string $class Native member's declaring class.
	 * @return bool Native view serialization dependencies used by both schema and sharing readbacks.
	 */
	public static function common(string $class): bool {
		return str_ends_with($class, '\\Model\\ViewSettings') || str_ends_with($class, '\\Constants\\ViewLayout');
	}

}
