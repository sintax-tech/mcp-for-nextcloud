<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi;

/**
 * The release of an optional app the oldest fixture was generated from: Deck and Talk come with the server tree
 * (NextcloudApiUsage::SOURCES), Mail is pinned apart (the fixture's `mailSources`).
 */
final class OldestCoveredRelease {
	/**
	 * The release of the app the fixture checks.
	 *
	 * @param array<string, string> $sources row of NextcloudApiUsage::SOURCES of the oldest covered major
	 * @param array<string, mixed> $fixture that major's fixture
	 * @param string $app app id, such as "deck" or "mail"
	 * @return string|null the release, or null when the fixture covers no release of the app
	 */
	public static function of(array $sources, array $fixture, string $app): ?string {
		return $sources[$app] ?? $fixture['mailSources'][$app]['version'] ?? null;
	}
}
