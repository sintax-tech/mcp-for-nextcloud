<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

/** The four round-six counterexamples really write once in PHP and must expose the write or report an unknown destination. */
final class CollectivesCallGraphRuntimeTest extends CallGraphTreeTestCase {
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

	/**
	 * @param array<string, string> $classes ordered declarations, with parent classes before children
	 * @return array{methods:array<string,string>, leaves:list<string>, unknown:list<string>} the graph of the same source
	 */
	private function runtimeAndUnknown(array $classes): array {
		$r = $this->reach($classes, 'Entry::read');
		$runner = '<?php namespace OCP\\Files { class Folder { public int $writes = 0;
			public function newFile(string $name): void { $this->writes++; } } }
			namespace { ';
		foreach ($classes as $declaration => $body) {
			$runner .= 'require ' . var_export($this->lib . '/Service/' . explode(' ', $declaration)[0] . '.php', true) . ';';
		}
		$runner .= '$f = new \\OCP\\Files\\Folder(); (new \\OCA\\Collectives\\Service\\Entry())->read($f); echo $f->writes; }';
		file_put_contents($this->lib . '/runtime.php', $runner);
		$process = proc_open([PHP_BINARY, $this->lib . '/runtime.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$this->assertIsResource($process);
		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$this->assertSame(0, proc_close($process), $errors);
		$this->assertSame('1', $output, 'the exact runtime reproduction calls the write double once');
		$this->assertTrue($r['unknown'] !== [] || in_array('OCP\\Files\\Folder::newFile', $r['leaves'], true),
			'a runtime write is followed or explicitly unknown, never accepted as a resolved read');
		return $r;
	}
}
