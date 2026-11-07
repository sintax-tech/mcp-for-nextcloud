<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use OCA\Mcp\Tests\Integration\Tables\Write\WriteSelectionExpectation;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** The integration oracle must preserve the native parser's exact types for every verified pin. */
final class WriteSelectionExpectationTest extends TestCase {
	/** @return void Tables2.2.2 is explicitly string, regardless of which database later stores the value. */
	public function testEveryPinnedParserHasAnExactExpectedType(): void {
		foreach (['1.0.0' => 2, '1.0.10' => '2', '2.0.0' => 2, '2.2.2' => '2', '2.3.2' => '2', '2.4.0' => '2'] as $version => $expected) {
			self::assertSame($expected, WriteSelectionExpectation::parsedId($version, 2), $version);
		}
	}

	/** @return void An unverified pin cannot silently inherit a guessed integer or string expectation. */
	public function testUnknownPinFailsClosed(): void {
		$this->expectException(RuntimeException::class);
		WriteSelectionExpectation::parsedId('unproven', 2);
	}
}
