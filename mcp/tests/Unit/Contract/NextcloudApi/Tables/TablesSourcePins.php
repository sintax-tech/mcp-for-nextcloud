<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Tables;

use RuntimeException;

/**
 * The Tables release each fixture of tests/fixtures/nextcloud-api is generated from (tables/tables-sources.json), and
 * the check the generator runs before it parses a source tree: the app under apps/tables must be exactly that release.
 */
final class TablesSourcePins {
	/** The pins file. */
	public const FILE = __DIR__ . '/../../../../fixtures/nextcloud-api/tables/tables-sources.json';

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * Every pin, by major.
	 *
	 * @return array<string, array{tables:array{version:string, url:string, sha256:string}, server:array{version:string, files:array<string, string>}}> the pins
	 */
	public static function all(): array {
		return json_decode((string)file_get_contents(self::FILE), true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * The pin of one major.
	 *
	 * @param string $major Nextcloud major of the fixture
	 * @return array{tables:array{version:string, url:string, sha256:string}, server:array{version:string, files:array<string, string>}} the pin
	 * @throws RuntimeException when tables-sources.json has no entry for the major
	 */
	public static function pin(string $major): array {
		$pins = self::all();
		if (!isset($pins[$major])) {
			throw new RuntimeException("tables-sources.json has no entry for Nextcloud $major");
		}
		return $pins[$major];
	}

	/**
	 * The pin of one major, once the app of the source tree is checked to be that very release.
	 *
	 * @param string $tree source tree with the Tables app under apps/tables
	 * @param string $major Nextcloud major of the fixture
	 * @return array{tables:array{version:string, url:string, sha256:string}, server:array{version:string, files:array<string, string>}} the pin
	 * @throws RuntimeException when the tree holds another Tables release, or none
	 */
	public static function verified(string $tree, string $major): array {
		$pin = self::pin($major);
		$manifest = $tree . '/apps/tables/appinfo/info.xml';
		$found = is_file($manifest) ? (string)(simplexml_load_file($manifest)->version ?? '') : '';
		if ($found !== $pin['tables']['version']) {
			throw new RuntimeException('Tables source version ' . ($found === '' ? 'missing' : $found)
				. ' does not match tables-sources.json (' . $pin['tables']['version'] . " for Nextcloud $major)");
		}
		return $pin;
	}
}
