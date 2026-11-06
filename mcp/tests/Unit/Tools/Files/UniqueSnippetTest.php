<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use OCA\Mcp\Modules\Files\FilesMessages;
use OCA\Mcp\Platform\Text\UniqueSnippet;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The replacement files_replace and notes_edit share: one occurrence, byte for byte, or nothing. */
final class UniqueSnippetTest extends TestCase {
	/** A single occurrence is replaced at its own offset and no other byte moves. */
	public function testTheOnlyOccurrenceIsReplacedAndEveryOtherByteKept(): void {
		$this->assertSame("a\r\nX\r\nc", UniqueSnippet::replace("a\r\nb\r\nc", 'b', 'X'));
		$this->assertSame('ac', UniqueSnippet::replace('abc', 'b', ''));
	}

	/** A snippet that is not there is refused. */
	public function testAMissingSnippetIsRefused(): void {
		$this->expectExceptionObject(new ToolFailure(FilesMessages::snippetMissing()));
		UniqueSnippet::replace('abc', 'z', 'y');
	}

	/** A snippet found more than once is refused. */
	public function testARepeatedSnippetIsRefused(): void {
		$this->expectExceptionObject(new ToolFailure(FilesMessages::snippetAmbiguous()));
		UniqueSnippet::replace('a a a', 'a', 'b');
	}

	/** @return array<string, array{string, string}> content and a snippet that matches it more than once, overlapping */
	public static function overlapping(): array {
		return [
			'"ana" at offsets 1 and 3 of "banana"' => ['banana', 'ana'],
			'"aaa" at offsets 0 and 1 of "aaaa"' => ['aaaa', 'aaa'],
			'"aa" at offsets 0, 1 and 2 of "aaaa"' => ['aaaa', 'aa'],
		];
	}

	/**
	 * NT-1 F1: occurrences that share bytes are still more than one, so nothing is replaced.
	 *
	 * @param string $content content searched
	 * @param string $old snippet that overlaps itself in it
	 */
	#[DataProvider('overlapping')]
	public function testOverlappingOccurrencesAreAmbiguous(string $content, string $old): void {
		$this->expectExceptionObject(new ToolFailure(FilesMessages::snippetAmbiguous()));
		UniqueSnippet::replace($content, $old, 'X');
	}

	/** A snippet that only looks repeated, but cannot overlap itself, is unique. */
	public function testASnippetThatCannotOverlapItselfIsStillUnique(): void {
		$this->assertSame('bXa', UniqueSnippet::replace('banana', 'anan', 'X'));
	}

	/** @return array<string, array{string, string, string}> content, snippet and the refusal it gets */
	public static function adversarial(): array {
		$ambiguous = FilesMessages::snippetAmbiguous();
		$missing = FilesMessages::snippetMissing();
		return [
			'short (strpos): 64 "a" in 10 MiB of "a", ambiguous' => [str_repeat('a', 10485760), str_repeat('a', 64), $ambiguous],
			'short (strpos): 63 "a" then "b" in 10 MiB of "a", missing' => [str_repeat('a', 10485760), str_repeat('a', 63) . 'b', $missing],
			'long (KMP): 64 KiB of "a" in 1 MiB of "a", ambiguous' => [str_repeat('a', 1048576), str_repeat('a', 65536), $ambiguous],
			'long (KMP): 64 KiB - 1 of "a" then "b" in 1 MiB of "a", missing' => [str_repeat('a', 1048576), str_repeat('a', 65535) . 'b', $missing],
			'longer than the content, missing at once' => [str_repeat('a', 100), str_repeat('a', 101), $missing],
		];
	}

	/**
	 * NT-1 F6: both searches stop at the second match, and neither is quadratic in the snippet: strpos() only below
	 * 64 bytes, Knuth-Morris-Pratt above. Counting every overlap took 9.7 s and strpos() on a long near-match 49 s. The
	 * bound is loose on purpose: the point is "not quadratic", not a benchmark.
	 *
	 * @param string $content content searched
	 * @param string $old snippet that repeats or almost matches everywhere
	 * @param string $refusal the message the search ends with
	 */
	#[DataProvider('adversarial')]
	public function testASnippetThatRepeatsOrAlmostMatchesEverywhereIsRefusedQuickly(string $content, string $old, string $refusal): void {
		$started = hrtime(true);
		try {
			UniqueSnippet::replace($content, $old, 'X');
			$this->fail('the snippet was accepted');
		} catch (ToolFailure $e) {
			$this->assertSame($refusal, $e->getMessage());
		}
		$this->assertLessThan(1.0, (hrtime(true) - $started) / 1e9);
	}

	/** @return array<string, array{string, bool}> a snippet and whether it is within the 64 KiB limit in bytes */
	public static function limits(): array {
		return [
			'65536 bytes of "a"' => [str_repeat('a', UniqueSnippet::MAX_BYTES), true],
			'65537 bytes of "a"' => [str_repeat('a', UniqueSnippet::MAX_BYTES + 1), false],
			'32768 "ç", 65536 bytes' => [str_repeat('ç', UniqueSnippet::MAX_BYTES / 2), true],
			'32769 "ç", 65538 bytes but fewer characters than the limit' => [str_repeat('ç', UniqueSnippet::MAX_BYTES / 2 + 1), false],
		];
	}

	/**
	 * The limit is in bytes: a multibyte snippet within the schema's character bound is still refused above it, and
	 * one at the limit is searched (and here replaced, being unique).
	 *
	 * @param string $old snippet
	 * @param bool $within whether it is at most MAX_BYTES
	 */
	#[DataProvider('limits')]
	public function testTheSnippetIsLimitedTo64KibInBytes(string $old, bool $within): void {
		$content = '<' . $old . '>';
		if ($within) {
			$this->assertSame('<X>', UniqueSnippet::replace($content, $old, 'X'));
			return;
		}
		try {
			UniqueSnippet::replace($content, $old, 'X');
			$this->fail('a snippet over the limit was searched');
		} catch (ToolFailure $e) {
			$this->assertStringContainsString((string)UniqueSnippet::MAX_BYTES, $e->getMessage());
			$this->assertNotSame(FilesMessages::snippetAmbiguous(), $e->getMessage());
		}
	}

	/** Only valid UTF-8 can be located as text. */
	public function testASnippetThatIsNotUtf8IsRefused(): void {
		UniqueSnippet::assertText('ação');
		$this->expectExceptionObject(new ToolFailure(FilesMessages::snippetNotUtf8()));
		UniqueSnippet::assertText("\xC3");
	}
}
