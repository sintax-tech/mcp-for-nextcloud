<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\Policy;

use OCA\Mcp\Access\Policy\DecimalCounter;
use PHPUnit\Framework\TestCase;

final class DecimalCounterTest extends TestCase {
    public function testCanonicalCountersPreserveBytesAbove32BitAndJavascriptPrecision(): void {
        foreach (['0', '2147483648', '9007199254740993', DecimalCounter::MAX_EXPECTED] as $value) {
            $this->assertSame($value, DecimalCounter::validate($value));
        }
    }

    public function testRejectsNonCanonicalValuesAndOverflowWithoutNumericConversion(): void {
        foreach (['', '00', '01', '-1', '+1', ' 1', '1 ', '1.0', '1e3', '١', DecimalCounter::MAX, '9223372036854775808', str_repeat('9', 100)] as $value) {
            try {
                DecimalCounter::validate($value);
                $this->fail('Invalid counter accepted: ' . $value);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(DecimalCounter::MAX, DecimalCounter::validate(DecimalCounter::MAX, DecimalCounter::MAX));
    }

    public function testCompareOrdersByMagnitudeAboveEveryPlatformLimitAndRejectsNonCanonicalValues(): void {
        $this->assertSame(0, DecimalCounter::compare('0', '0'));
        $this->assertSame(-1, DecimalCounter::compare('9', '10'));
        $this->assertSame(1, DecimalCounter::compare('10', '9'));
        $this->assertSame(1, DecimalCounter::compare('9007199254740993', '9007199254740992'));
        $this->assertSame(-1, DecimalCounter::compare(DecimalCounter::MAX_EXPECTED, DecimalCounter::MAX));
        foreach ([['01', '1'], ['1', '-1'], ['1', '9223372036854775808']] as [$a, $b]) {
            try {
                DecimalCounter::compare($a, $b);
                $this->fail("Compared $a with $b");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testIncrementCarriesOnTheStringUpToTheLargestCounter(): void {
        $this->assertSame('1', DecimalCounter::increment('0'));
        $this->assertSame('10', DecimalCounter::increment('9'));
        $this->assertSame('1000', DecimalCounter::increment('999'));
        $this->assertSame('9007199254740993', DecimalCounter::increment('9007199254740992'));
        $this->assertSame(DecimalCounter::MAX, DecimalCounter::increment(DecimalCounter::MAX_EXPECTED));
        foreach ([DecimalCounter::MAX, '01', '-1', ''] as $bad) {
            try {
                DecimalCounter::increment($bad);
                $this->fail('Accepted ' . $bad);
            } catch (\InvalidArgumentException) {
            }
        }
    }
}
