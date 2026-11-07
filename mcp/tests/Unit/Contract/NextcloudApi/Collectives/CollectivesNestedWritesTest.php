<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

/** A nested array write cannot retain only the scalar evidence of its original literal. */
final class CollectivesNestedWritesTest extends RuntimeTreeTestCase {
	/** @return void every selected nesting level receives the object assigned at runtime */
	public function testNestedLocalWriteKeepsTheObject(): void {
		$r = $this->runtimeAndUnknown([
			'Text' => 'public function __construct(private Folder $f) {} public function __toString(): string { $this->f->newFile("nested"); return "a"; }',
			'Entry' => 'public function read(Folder $f): void { $m = new Text($f); $a = ["r" => ["id" => "s"]]; $a["r"]["id"] = $m; strval($a["r"]["id"]); }',
		]);
		$this->assertArrayHasKey('OCA\\Collectives\\Service\\Text::__toString', $r['methods']);
	}

	/** @return void writes through any number of static property indices invalidate scalar-only initial evidence */
	public function testNestedStaticPropertyWriteInvalidatesTheLiteral(): void {
		$this->runtimeAndUnknown([
			'Text' => 'public function __construct(private Folder $f) {} public function __toString(): string { $this->f->newFile("static"); return "a"; }',
			'Entry' => 'private static array $t = [["id" => "safe"]]; public function read(Folder $f): void { self::$t[0]["id"] = new Text($f); foreach (self::$t as $row) { strval($row["id"]); } }',
		]);
	}

	/** @return void PHP auto-incremented literal keys follow the previous numeric key, including a negative first key */
	public function testImplicitNumericKeysDoNotLoseObjects(): void {
		foreach (['[3 => "s", new Text($f)]' => 4, '[-5 => "s", new Text($f)]' => -4] as $literal => $key) {
			$this->runtimeAndUnknown([
				'Text' => 'public function __construct(private Folder $f) {} public function __toString(): string { $this->f->newFile("key"); return "a"; }',
				'Entry' => 'public function read(Folder $f): void { $a = ' . $literal . '; strval($a[' . $key . ']); }',
			]);
		}
	}
}
