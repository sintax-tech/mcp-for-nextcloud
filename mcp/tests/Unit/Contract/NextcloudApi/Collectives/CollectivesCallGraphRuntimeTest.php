<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

/** The four round-six counterexamples really write once in PHP and must expose the write or report an unknown destination. */
final class CollectivesCallGraphRuntimeTest extends RuntimeTreeTestCase {
	/** @return void PHP's mixed-case __Get must have the same refusal as __get */
	public function testMixedCaseMagicWritesOnceAndIsCaught(): void {
		$this->runtimeAndUnknown([
			'Magic' => 'public function __construct(private Folder $f) {}
				public function __Get($name) { $this->f->newFile("g"); return null; }',
			'Entry' => 'public function read(Folder $f): void { $m = new Magic($f); $x = $m->missing; }',
		]);
	}

	/** @return void .= is covered by the generic instance rule, without enumerating string conversion syntax */
	public function testConcatAssignmentWritesOnceAndIsCaught(): void {
		$this->runtimeAndUnknown([
			'Text' => 'public function __construct(private Folder $f) {}
				public function __toString(): string { $this->f->newFile("t"); return "x"; }',
			'Entry' => 'public function read(Folder $f): void { $m = new Text($f); $t = ""; $t .= $m; }',
		]);
	}

	/** @return void __call on a followed subclass of an external class cannot turn an absent method into a leaf */
	public function testMagicWithAnExternalParentWritesOnceAndIsCaught(): void {
		$r = $this->runtimeAndUnknown([
			'Magic extends \\RuntimeException' => 'public function __construct(private Folder $f) {}
				public function __call($name, $args) { $this->f->newFile("c"); }',
			'Entry' => 'public function read(Folder $f): void { $m = new Magic($f); $m->anything(); }',
		]);
		$this->assertNotContains('RuntimeException::anything', $r['leaves']);
	}

	/** @return void late static construction reaches the writing subclass constructor and exposes its write */
	public function testLateStaticConstructionWritesOnceAndIsCaught(): void {
		$r = $this->runtimeAndUnknown([
			'ParentFactory' => 'public function __construct(Folder $f) {}
				public static function make(Folder $f) { return new static($f); }',
			'ChildFactory extends ParentFactory' => 'public function __construct(Folder $f) { $f->newFile("s"); }',
			'Entry' => 'public function read(Folder $f): void { ChildFactory::make($f); }',
		]);
		$this->assertArrayHasKey('OCA\\Collectives\\Service\\ChildFactory::__construct', $r['methods']);
		$this->assertArrayHasKey('OCA\\Collectives\\Service\\ParentFactory::__construct', $r['methods']);
	}

	/** @return void an unlisted match never erases an object or an array containing that object */
	public function testMatchCannotEraseTheRuntimeWrite(): void {
		foreach (['$t = ""; $t .= match (1) { default => $m };',
			'foreach (match (1) { default => [$m] } as $item) { $t = ""; $t .= $item; }'] as $use) {
			$this->runtimeAndUnknown([
				'Text' => 'public function __construct(private Folder $f) {}
					public function __toString(): string { $this->f->newFile("match"); return ""; }',
				'Entry' => 'public function read(Folder $f): void { $m = new Text($f); ' . $use . ' }',
			]);
		}
	}

	/** @return void func_get_args has no admitted model, so its elements cannot be guessed scalar */
	public function testFuncGetArgsCannotEraseTheRuntimeWrite(): void {
		$this->runtimeAndUnknown([
			'Text' => 'public function __construct(private Folder $f) {}
				public function __toString(): string { $this->f->newFile("args"); return ""; }',
			'Passer' => 'public static function pass(): void { foreach (func_get_args() as $item) { $t = ""; $t .= $item; } }',
			'Entry' => 'public function read(Folder $f): void { Passer::pass(new Text($f)); }',
		]);
	}

	/** @return void empty and coalesce both expose the writing __isset even when it returns false */
	public function testPropertyContextsCannotEraseTheRuntimeWrite(): void {
		foreach (['empty($m->missing);', '$x = $m->missing ?? 1;'] as $use) {
			$this->runtimeAndUnknown([
				'Magic' => 'public function __construct(private Folder $f) {}
					public function __isset($name): bool { $this->f->newFile("isset"); return false; }',
				'Entry' => 'public function read(Folder $f): void { $m = new Magic($f); ' . $use . ' }',
			]);
		}
	}

	/** @return void every listed stringifying function follows object arguments, including array elements */
	public function testStringifyingFunctionsCannotEraseTheRuntimeWrite(): void {
		foreach (['strval($m);', 'sprintf("%s", $m);', 'implode("", [$m]);'] as $use) {
			$this->runtimeAndUnknown([
				'Text' => 'public function __construct(private Folder $f) {}
					public function __toString(): string { $this->f->newFile("convert"); return ""; }',
				'Entry' => 'public function read(Folder $f): void { $m = new Text($f); ' . $use . ' }',
			]);
		}
	}

}
