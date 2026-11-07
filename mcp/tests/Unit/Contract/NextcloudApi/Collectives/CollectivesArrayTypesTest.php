<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

/** Scalar arrays need evidence; object elements and unknown inputs must survive every inferred array operation. */
final class CollectivesArrayTypesTest extends CallGraphTreeTestCase {
	/** @return void literals, typed docs, internal returns and destructuring prove scalar values */
	public function testProvenScalarArraysAndDestructuring(): void {
		$r = $this->reach([
			'Entry' => '/** @param string[] $words */
				public function read(array $words): void {
				foreach ($words as $word) { $s = "prefix" . $word; }
				[$a, $b] = explode(" ", "a b"); $s = $a . $b;
				foreach (["a", "b"] as $literal) { $s .= $literal; }
				$mapped = array_map(fn ($item): string => $item, $words);
				foreach ($mapped as $mappedWord) { $s .= $mappedWord; }
			}',
		], 'Entry::read');
		$this->assertSame([], $r['unknown']);
	}

	/** @return void constructor docblocks and static scalar literal tables retain their evidence */
	public function testConstructorAndStaticLiteralEvidence(): void {
		$r = $this->reach([
			'Entry' => 'private array $version; private static $table = ["a", "b"];
				public function __construct() { /** @var int[] $value */ $this->version = $value; }
				public function read(): void { [$a, $b] = $this->version; $text = $a . $b; foreach (self::$table as $part) { $text .= $part; } }',
		], 'Entry::read');
		$this->assertSame([], $r['unknown']);
	}

	/** @return void a bare array declaration does not prove its elements are scalar */
	public function testAnArrayWithUnknownElementsStaysUnknown(): void {
		$r = $this->reach(['Entry' => 'public function read(array $items): void {
			foreach ($items as $item) { $text = ""; $text .= $item; }
		}'], 'Entry::read');
		$this->assertUnknown($r['unknown'], 'Entry', 'implicit receiver of unknown type');
	}

	/** @return void an object literal in an array remains an object and exposes its string conversion write */
	public function testObjectElementsAreNeverCollapsedToScalarArrays(): void {
		$r = $this->reach([
			'Text' => 'public function __toString(): string { (new Folder())->newFile("object"); return ""; }',
			'Entry' => 'public function read(): void {
				foreach ([new Text()] as $item) { $text = ""; $text .= $item; }
			}',
		], 'Entry::read');
		$this->assertContains('OCP\\Files\\Folder::newFile', $r['leaves']);
	}

	/** @return void call-site inference unions scalar and object arrays instead of retaining only the last caller */
	public function testAllReachedArgumentTypesAreKept(): void {
		file_put_contents($this->lib . '/Service/functions.php', '<?php function use_items(array $items) {
			foreach ($items as $item) { $text = ""; $text .= $item; }
		}');
		$r = $this->reach([
			'Text' => 'public function __toString(): string { (new Folder())->newFile("bound"); return ""; }',
			'Entry' => 'public function read(): void { \\use_items([new Text()]); \\use_items(["scalar"]); }',
		], 'Entry::read');
		$this->assertContains('OCP\\Files\\Folder::newFile', $r['leaves']);
	}

	/** @return void a proven scalar call does not erase an unknown argument arriving at the same function */
	public function testUnknownArgumentsCannotBeOverwrittenByScalarArguments(): void {
		file_put_contents($this->lib . '/Service/functions.php', '<?php function use_items(array $items) {
			foreach ($items as $item) { $text = ""; $text .= $item; }
		}');
		$r = $this->reach(['Entry' => 'public function read(array $unknown): void {
			\\use_items($unknown); \\use_items(["scalar"]);
		}'], 'Entry::read');
		$this->assertNotSame([], $r['unknown']);
	}
}
