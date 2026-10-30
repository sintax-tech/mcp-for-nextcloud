<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Tables;

/**
 * The behaviour of each compared Tables release, as tables/generate-tables-behavior.php read it from the official
 * sources (tables-behavior.json).
 */
final class TablesBehavior {
	/** The behaviour fixture. */
	public const FILE = __DIR__ . '/../../../../fixtures/nextcloud-api/tables/tables-behavior.json';

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The behaviour of every compared release, by version.
	 *
	 * @return array<string, array<string, mixed>> the behaviour
	 */
	public static function versions(): array {
		return self::fixture()['versions'];
	}

	/**
	 * The behaviour of one release.
	 *
	 * @param string $version Tables version
	 * @return array<string, mixed> the behaviour
	 */
	public static function of(string $version): array {
		return self::versions()[$version];
	}

	/**
	 * The tarball of every compared release, by version.
	 *
	 * @return array<string, array{url:string, sha256:string}> URL and SHA256
	 */
	public static function sources(): array {
		return self::fixture()['sources'];
	}

	/**
	 * The fixture.
	 *
	 * @return array{sources:array<string, array{url:string, sha256:string}>, versions:array<string, array<string, mixed>>} the fixture
	 */
	private static function fixture(): array {
		return json_decode((string)file_get_contents(self::FILE), true, 512, JSON_THROW_ON_ERROR);
	}
}
