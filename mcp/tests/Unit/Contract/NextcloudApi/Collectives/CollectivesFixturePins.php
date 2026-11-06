<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\FixtureFile;

/** The optional Collectives source pin of a fixture; the fixture writer and the release detection only delegate. */
final class CollectivesFixturePins {
	/** Static helper only; never instantiated. */
	private function __construct() {
	}

	/**
	 * @param array<string, mixed> $fixture a fixture
	 * @return string its Collectives source line, or '' for a fixture generated without Collectives
	 */
	public static function header(array $fixture): string {
		return isset($fixture['collectivesSources']) ? "\t'collectivesSources' => " . FixtureFile::literal($fixture['collectivesSources']) . ",\n" : '';
	}

	/**
	 * @param array<string, mixed> $fixture a fixture
	 * @param string $app requested app
	 * @return string|null the Collectives release the fixture checks, null for any other app
	 */
	public static function version(array $fixture, string $app): ?string {
		return $app === 'collectives' ? ($fixture['collectivesSources']['collectives']['version'] ?? null) : null;
	}
}
