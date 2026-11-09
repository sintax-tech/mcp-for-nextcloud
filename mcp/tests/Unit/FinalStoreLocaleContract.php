<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Keeps store descriptions on the prepared release series without prerelease sales wording. */
final class FinalStoreLocaleContract {
	/** Release identity shared by manifest and store contract tests; change only this value for the final release. */
	public const VERSION = '1.3.3';

	/** @return string Public version series, without the technical prerelease suffix. */
	public static function displayVersion(): string {
		return explode('-', self::VERSION, 2)[0];
	}

	/**
	 * Require the public version series without prerelease sales wording or a publication claim.
	 *
	 * @param array<string, string> $texts Descriptions indexed by locale.
	 * @return void
	 */
	public static function descriptions(array $texts): void {
		foreach ($texts as $locale => $text) {
			TestCase::assertStringContainsString(self::displayVersion(), $text, $locale);
			TestCase::assertStringNotContainsString(self::displayVersion() . '-rc.', $text, $locale);
			foreach (['release candidate', 'Candidata local', 'Release-Kandidat'] as $obsolete) {
				TestCase::assertStringNotContainsStringIgnoringCase($obsolete, $text, $locale);
			}
		}
	}
}
