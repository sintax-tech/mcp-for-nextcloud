<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Platform\Logging;

use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Platform\Logging\ExceptionDetails;
use PHPUnit\Framework\TestCase;

/** Exception identities never carry private messages, traces or non-integer codes. */
final class ExceptionDetailsTest extends TestCase {
	/** @return void Both the tool boundary and lower layers apply the same privacy policy. */
	public function testOnlyClassAndIntegerCodeSurvive(): void {
		$previous = new \RuntimeException('/private/Salary.txt', 9);
		$failure = new \RuntimeException('private recipient@example.org', 42, $previous);
		self::assertSame('RuntimeException (code 42)', ExceptionDetails::describe($failure));
		self::assertSame(ExceptionDetails::describe($failure), ToolRegistry::loggable($failure));
		self::assertSame('Error (code 0)', ExceptionDetails::describe(new \Error('/private/Salary.txt')));
	}

	/** @return void A native string code cannot become a second channel for private text. */
	public function testNonIntegerNativeCodeIsReplacedWithZero(): void {
		$failure = new \PDOException('/private/Salary.txt');
		(new \ReflectionProperty(\Exception::class, 'code'))->setValue($failure, 'private-code');
		self::assertSame(0, ExceptionDetails::code($failure));
		self::assertSame('PDOException (code 0)', ExceptionDetails::describe($failure));
	}
}
