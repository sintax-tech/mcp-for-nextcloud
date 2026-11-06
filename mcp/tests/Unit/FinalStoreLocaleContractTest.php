<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Keeps public store descriptions distinct from technical prerelease identifiers in every locale. */
final class FinalStoreLocaleContractTest extends TestCase {
	/**
	 * Provide all supported store locales.
	 *
	 * @return iterable<string, array{string}> Locales.
	 */
	public static function locales(): iterable {
		foreach (['en', 'pt-br', 'es', 'de'] as $locale) {
			yield $locale => [$locale];
		}
	}

	/**
	 * Accept the public version series in each locale; retain the historical test identity.
	 *
	 * @param string $locale Store locale.
	 * @return void
	 */
	#[DataProvider('locales')]
	public function testFinalVersionIsAccepted(string $locale): void {
		FinalStoreLocaleContract::descriptions([$locale => FinalStoreLocaleContract::displayVersion()]);
	}

	/**
	 * Refuse a technical RC identifier even though it contains the public version series as a substring.
	 *
	 * @param string $locale Store locale.
	 * @return void
	 */
	#[DataProvider('locales')]
	public function testReleaseCandidateIsRefused(string $locale): void {
		$this->expectException(AssertionFailedError::class);
		FinalStoreLocaleContract::descriptions([$locale => FinalStoreLocaleContract::displayVersion() . '-rc.1']);
	}
}
