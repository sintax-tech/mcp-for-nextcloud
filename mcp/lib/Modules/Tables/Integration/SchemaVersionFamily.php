<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Integration;

/** Routes only evidence-bounded native families, independently of actor grants. */
final class SchemaVersionFamily {
	/**
	 * @param string $version Installed stable Tables version.
	 * @param int $major Installed Nextcloud major.
	 * @return string|null Proven profile, or no schema/sharing capability.
	 */
	public static function of(string $version, int $major): ?string {
		if (!preg_match('/^\d+\.\d+\.\d+$/D', $version) || !in_array($major, [32, 33, 34, 35], true)) return null;
		if ($version === '2.4.0') return in_array($major, [33, 34, 35], true) ? '2.4.0' : null;
		foreach ([['1.0.10', '1.0.11', [32], '1.0.10'], ['2.2.2', '2.2.3', [33, 34, 35], '2.2.2'], ['2.3.1', '2.3.3', [33, 34, 35], '2.3.2']] as [$min, $max, $majors, $profile]) {
			if (in_array($major, $majors, true) && version_compare($version, $min, '>=') && version_compare($version, $max, '<')) return $profile;
		}
		return null;
	}

	/**
	 * @param string $profile Proven native profile.
	 * @return bool Whether the native DTO preserves description zero and normalizes selection halves together.
	 */
	public static function modernDto(string $profile): bool {
		return in_array($profile, ['2.3.2', '2.4.0'], true);
	}
}
