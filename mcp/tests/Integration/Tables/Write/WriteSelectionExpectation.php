<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Tables\Write;

use RuntimeException;

/** Independent oracle from the verified native parser fixture, without consulting MCP's value domain. */
final class WriteSelectionExpectation {
	/**
	 * @param string $version Exact Tables pin installed by the cell harness.
	 * @param int $id Submitted single-selection option identifier.
	 * @return int|string Exact PHP type produced by that pin's parser, before database access.
	 */
	public static function parsedId(string $version, int $id): int|string {
		$fixture = json_decode(file_get_contents(dirname(__DIR__, 3) . '/fixtures/nextcloud-api/tables/tables-write.json'), true, 512, JSON_THROW_ON_ERROR);
		return match ($fixture['versions'][$version]['singleParse'] ?? null) {
			'string' => (string)$id,
			'integer' => $id,
			default => throw new RuntimeException('Unproven Tables pin for the single-selection parser expectation'),
		};
	}
}
