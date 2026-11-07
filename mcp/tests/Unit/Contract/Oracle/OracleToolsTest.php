<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\Oracle;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * The oracle's own helpers: a diff that points at the changed line, a normalization that touches only the documented
 * keys, and a golden comparison that fails instead of creating a missing file.
 */
final class OracleToolsTest extends TestCase {
	/** @return void the diff shows the changed line with its number and context, nothing for equal texts */
	public function testLineDiffShowsTheChangedLine(): void {
		$old = implode("\n", ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']);
		$new = implode("\n", ['a', 'b', 'c', 'd', 'X', 'f', 'g', 'h']);
		$this->assertSame('', LineDiff::render($old, $old));
		$this->assertSame("--- golden\n+++ actual\n  2: b\n  3: c\n  4: d\n- 5: e\n+ 5: X\n  6: f\n  7: g\n  8: h", LineDiff::render($old, $new));
	}

	/** @return void a moved line reads as one removal and one addition, not as a rewrite */
	public function testLineDiffAlignsAMovedLine(): void {
		$diff = LineDiff::render("a\nb\nc\nd", "b\nc\na\nd");
		$this->assertStringContainsString('- 1: a', $diff);
		$this->assertStringContainsString('+ 3: a', $diff);
		$this->assertStringNotContainsString('- 2: b', $diff);
	}

	/** @return void only listed keys are replaced, equal values share a number and long values are replaced in text */
	public function testVolatileFieldsReplaceOnlyListedKeys(): void {
		$state = str_repeat('f', 64);
		$value = (object)['plan_state' => $state, 'other' => $state, 'text' => 'send plan_state ' . $state,
			'nested' => [(object)['plan_state' => $state], (object)['plan_state' => 'short']], 'title' => 'etag'];
		$normalized = VolatileFields::normalize($value);
		$this->assertSame('<plan_state#1>', $normalized->plan_state);
		$this->assertSame('<plan_state#1>', $normalized->other, 'the same value elsewhere is the same placeholder');
		$this->assertSame('send plan_state <plan_state#1>', $normalized->text);
		$this->assertSame('<plan_state#1>', $normalized->nested[0]->plan_state);
		$this->assertSame('<plan_state#2>', $normalized->nested[1]->plan_state);
		$this->assertSame('etag', $normalized->title, 'a value that merely reads like a key name is kept');
		$this->assertSame(['x' => '<v>'], VolatileFields::normalize(['x' => '1.2.3'], ['1.2.3' => '<v>']));
	}

	/** @return void a missing golden fails (it is never created outside the update mode) */
	public function testMissingGoldenFails(): void {
		if (GoldenFile::updating()) {
			$this->markTestSkipped('update mode writes golden files');
		}
		$path = sys_get_temp_dir() . '/mcp-oracle-missing-' . bin2hex(random_bytes(4)) . '.json';
		try {
			GoldenFile::assertMatches($this, $path, ['a' => 1]);
			$this->fail('a missing golden passed');
		} catch (AssertionFailedError $e) {
			$this->assertStringContainsString('Golden file missing', $e->getMessage());
		}
		$this->assertFileDoesNotExist($path);
	}

	/** @return void the encoding keeps {} and [] apart and ends with a newline */
	public function testEncodingKeepsObjectsAndLists(): void {
		$this->assertSame("{\n    \"o\": {},\n    \"l\": []\n}\n", GoldenFile::encode((object)['o' => new \stdClass(), 'l' => []]));
	}
}
