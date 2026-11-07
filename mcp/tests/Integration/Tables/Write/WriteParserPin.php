<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Tables\Write;

/** Reads parser facts from the independent verified pin without expanding the legacy minimum-version fixture. */
final class WriteParserPin {
	/**
	 * @param string $version Installed source pin.
	 * @return array<string,mixed> Proven parser facts, or no facts for an unproven pin.
	 */
	public static function facts(string $version): array {
		$base = dirname(__DIR__, 3) . '/fixtures/nextcloud-api/tables/';
		if ($version === '2.4.0') {
			$data = json_decode(file_get_contents($base . 'tables-2.4.0.json'), true, 512, JSON_THROW_ON_ERROR);
			return $data['rowWrite']['facts'];
		}
		$data = json_decode(file_get_contents($base . 'tables-write.json'), true, 512, JSON_THROW_ON_ERROR);
		return $data['versions'][$version] ?? [];
	}
}
