<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Forms;

/** Version pins and fixture metadata; NC31 metadata is a signature source only, outside MCP's supported server range. */
final class FormsSourcePins {
	/** Reads the four approved source pins.
	 * @return array<string,string> Version to official archive SHA256.
	 */
	public static function all(): array {
		return json_decode((string)file_get_contents(__DIR__ . '/../../../../fixtures/nextcloud-api/forms/pins.json'), true, 16, JSON_THROW_ON_ERROR);
	}

	/** Adds the Forms source pin to the existing aggregate fixture format.
	 * @param array<string,mixed> $fixture Existing generated fixture.
	 * @return string Optional PHP source line.
	 */
	public static function line(array $fixture): string {
		return isset($fixture['formsSources']) ? "\t'formsSources' => " . \OCA\Mcp\Tests\Unit\Contract\NextcloudApi\FixtureFile::literal($fixture['formsSources']) . ",\n" : '';
	}

	/** Resolves the oldest source covered for version-floor policy checks.
	 * @param array<string,mixed> $fixture Aggregate fixture.
	 * @param string $app Optional app id.
	 * @return string|null Source version, not an installability claim for an unsupported NC major.
	 */
	public static function version(array $fixture, string $app): ?string { return $fixture['formsSources'][$app]['version'] ?? null; }
}
