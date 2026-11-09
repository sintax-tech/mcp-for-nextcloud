<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Platform\Php;

use OCA\Mcp\Platform\Php\IniQuantity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ini_parse_quantity is the only size parser, and a warning for a bad value stays inside it. */
final class IniQuantityTest extends TestCase {
	/** @return array<string, array{string, bool, bool, int}> value, unlimited, rejected, bytes */
	public static function quantities(): array {
		return [
			'minus one' => ['-1', true, false, 0],
			'empty' => ['', true, false, 0],
			'blank' => ['  ', true, false, 0],
			'megabytes' => ['128M', false, false, 128 * 1024 * 1024],
			'gigabytes' => ['1G', false, false, 1024 ** 3],
			'lowercase' => ['32m', false, false, 32 * 1024 * 1024],
			'spaced suffix' => [' 128 M ', false, false, 128 * 1024 * 1024],
			'raw bytes' => ['4096', false, false, 4096],
			'fifty' => ['50M', false, false, 50 * 1024 * 1024],
			'hex' => ['0x8000000', false, false, 128 * 1024 * 1024],
			'octal' => ['0o200000000', false, false, 32 * 1024 * 1024],
			'signed' => ['+128M', false, false, 128 * 1024 * 1024],
			'trailing letter' => ['256MB', false, false, 256],
			'terabyte' => ['1T', false, false, 1],
			'zero' => ['0', false, false, 0],
			'unparsable' => ['nope', false, true, 0],
			'other negative' => ['-2', false, false, -2],
		];
	}

	#[DataProvider('quantities')]
	public function testParseAgreesWithPhp(string $value, bool $unlimited, bool $rejected, int $bytes): void {
		$parsed = IniQuantity::parse($value);
		$this->assertSame($unlimited, $parsed->unlimited(), $value);
		$this->assertSame($rejected, $parsed->rejected(), $value);
		$this->assertSame($bytes, $parsed->bytes(), $value);
	}

	/**
	 * Checkout keeps a positive size and treats -1, empty and zero as no PHP cap.
	 * These are the directives the old parser already accepted.
	 *
	 * @return array<string, array{string, int}>
	 */
	public static function checkoutDirectives(): array {
		return [
			'128M' => ['128M', 128 * 1024 * 1024],
			'1G' => ['1G', 1024 ** 3],
			'minus one' => ['-1', 0],
			'bytes' => ['4096', 4096],
			'zero' => ['0', 0],
			'empty' => ['', 0],
			'50M' => ['50M', 50 * 1024 * 1024],
		];
	}

	#[DataProvider('checkoutDirectives')]
	public function testCheckoutKeepsCommonValues(string $value, int $bytes): void {
		$parsed = IniQuantity::parse($value)->bytes();
		$this->assertSame($bytes, $parsed > 0 ? $parsed : 0);
	}

	public function testAnInvalidValueDoesNotEscapeAsAWarning(): void {
		$seen = false;
		set_error_handler(static function () use (&$seen): bool {
			$seen = true;
			return true;
		});
		try {
			$parsed = IniQuantity::parse('nope');
			$this->assertTrue($parsed->rejected());
			$this->assertSame(0, $parsed->bytes());
			$this->assertFalse($seen, 'the warning is swallowed before the outer handler');
			trigger_error('probe', E_USER_WARNING);
			$this->assertTrue($seen, 'the previous handler is restored');
		} finally {
			restore_error_handler();
		}
	}
}
