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

/** Discriminates a final description from the previous RC in every store locale. */
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
	 * Accept the final version in each locale.
	 *
	 * @param string $locale Store locale.
	 * @return void
	 */
	#[DataProvider('locales')]
	public function testFinalVersionIsAccepted(string $locale): void {
		FinalStoreLocaleContract::descriptions([$locale => '1.2.2']);
	}

	/**
	 * Refuse the old RC even though it contains the final version as a substring.
	 *
	 * @param string $locale Store locale.
	 * @return void
	 */
	#[DataProvider('locales')]
	public function testReleaseCandidateIsRefused(string $locale): void {
		$this->expectException(AssertionFailedError::class);
		FinalStoreLocaleContract::descriptions([$locale => '1.2.2-rc.1']);
	}
}
