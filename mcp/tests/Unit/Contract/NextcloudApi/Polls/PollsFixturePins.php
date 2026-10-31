<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Polls;

use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\FixtureFile;

/** Focused optional Polls pins extension; existing fixture writing and release detection only delegate. */
final class PollsFixturePins {
	/**
	 * Serializes the optional source pin without changing old fixture sections.
	 * @param array<string,mixed> $fixture fixture
	 * @return string source header line, or empty for a fixture not generated with Polls
	 */
	public static function header(array $fixture): string {
		return isset($fixture['pollsSources']) ? "\t'pollsSources' => " . FixtureFile::literal($fixture['pollsSources']) . ",\n" : '';
	}
	/**
	 * Detects the optional oldest covered release.
	 * @param array<string,mixed> $fixture fixture
	 * @param string $app requested app
	 * @return string|null version only for Polls
	 */
	public static function version(array $fixture, string $app): ?string {
		return $app === 'polls' ? ($fixture['pollsSources']['polls']['version'] ?? null) : null;
	}
}
