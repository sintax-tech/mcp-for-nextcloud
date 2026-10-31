<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Keeps the four current store descriptions on the final release version. */
final class FinalStoreLocaleContract {
	/**
	 * Require the final release identity without claiming publication.
	 *
	 * @param array<string, string> $texts Descriptions indexed by locale.
	 * @return void
	 */
	public static function descriptions(array $texts): void {
		foreach ($texts as $locale => $text) {
			TestCase::assertStringContainsString('1.2.5', $text, $locale);
			TestCase::assertStringNotContainsString('1.2.5-rc.', $text, $locale);
			foreach (['release candidate', 'Candidata local', 'Release-Kandidat'] as $obsolete) {
				TestCase::assertStringNotContainsStringIgnoringCase($obsolete, $text, $locale);
			}
		}
	}
}
