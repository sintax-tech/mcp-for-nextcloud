<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

/** Independent round-nine reproductions, executed against the same source used by the graph. */
final class CollectivesRoundNineRuntimeTest extends RuntimeTreeTestCase {
	private const TEXT = 'public function __construct(private Folder $f) {}
		/** @return void executes the independent runtime reproduction */
	public function __toString(): string { $this->f->newFile("convert"); return "a"; }';
	/**
	 * @param array<string,string> $classes source declarations
	 * @return void executes the independent runtime reproduction
	 */
	private function check(array $classes): void {
		$this->runtimeAndUnknown($classes);
	}
	/**
	 * @param string $use expression exercising coercion
	 * @param array<string,string> $extra additional declarations
	 * @return void executes the independent runtime reproduction
	 */
	private function text(string $use, array $extra = []): void {
		$this->check([...$extra, 'Text' => self::TEXT, 'Entry' => 'public function read(Folder $f): void { $m = new Text($f); ' . $use . ' }']);
	}
	/** @return void executes the independent runtime reproduction */
	public function testDestruct(): void {
		$this->check(['Gone' => 'public function __construct(private Folder $f) {} public function __destruct() { $this->f->newFile("d"); }',
			'Entry' => 'public function read(Folder $f): void { new Gone($f); }']);
	}
	/** @return void executes the independent runtime reproduction */
	public function testTypedStringParam(): void {
		$this->text('$this->take($m); } private function take(string $s): void {');
	}
	/** @return void executes the independent runtime reproduction */
	public function testTypedStringReturn(): void {
		$this->text('$x = $this->give($m); } private function give(Text $m): string { return $m;');
	}
	/** @return void executes the independent runtime reproduction */
	public function testTypedStringProperty(): void {
		$this->text('$this->s = $m; } private string $s = ""; private function unused(): void {');
	}
	/** @return void executes the independent runtime reproduction */
	public function testInternalCtorString(): void {
		$this->text('$e = new \RuntimeException($m);');
	}
	/** @return void executes the independent runtime reproduction */
	public function testNestedPropertyCompare(): void {
		$this->check(['Text' => self::TEXT, 'Pair' => 'public function __construct(public mixed $v) {}',
			'Entry' => 'public function read(Folder $f): void { $x = new Pair(new Text($f)) == new Pair("a"); }']);
	}
	/** @return void executes the independent runtime reproduction */
	public function testInclude(): void {
		$this->text('@include $m;');
	}
	/** @return void executes the independent runtime reproduction */
	public function testSpreadArray(): void {
		$this->check(['Bag implements \IteratorAggregate' => 'public function __construct(private Folder $f) {}
			/** @return void executes the independent runtime reproduction */
	public function getIterator(): \Traversable { $this->f->newFile("i"); return new \ArrayIterator([]); }',
			'Entry' => 'public function read(Folder $f): void { $x = [...new Bag($f)]; }']);
	}
	/**
	 * @param string $writing ArrayAccess method that writes
	 * @param string $use triggering expression
	 * @return void executes the independent runtime reproduction
	 */
	private function access(string $writing, string $use): void {
		$body = 'public function __construct(private Folder $f) {}';
		foreach (['offsetGet' => 'mixed $key): mixed', 'offsetExists' => 'mixed $key): bool',
			'offsetSet' => 'mixed $key, mixed $value): void', 'offsetUnset' => 'mixed $key): void'] as $method => $sig) {
			$w = $method === $writing ? '$this->f->newFile("o");' : '';
			$ret = match ($method) { 'offsetGet' => str_contains($use, '++') ? 'return 1;' : 'return [1];', 'offsetExists' => 'return false;', default => '' };
			$body .= 'public function ' . $method . '(' . $sig . '{' . $w . $ret . '}';
		}
		$this->check(['Bag implements \ArrayAccess' => $body,
			'Entry' => 'public function read(Folder $f): void { $b = new Bag($f); ' . $use . ' }']);
	}
	/** @return void executes the independent runtime reproduction */
	public function testListDestructure(): void { $this->access('offsetGet', '[$a] = $b;'); }
	/** @return void executes the independent runtime reproduction */
	public function testKeyedListDestructure(): void { $this->access('offsetGet', '["k" => $a] = $b;'); }
	/** @return void executes the independent runtime reproduction */
	public function testForeachList(): void { $this->access('offsetGet', 'foreach ([$b] as [$a]) {}'); }
	/** @return void executes the independent runtime reproduction */
	public function testNestedIsset(): void { $this->access('offsetExists', '$x = isset($b["k"]["j"]);'); }
	/** @return void executes the independent runtime reproduction */
	public function testNestedEmpty(): void { $this->access('offsetExists', '$x = empty($b["k"]["j"]);'); }
	/** @return void executes the independent runtime reproduction */
	public function testNestedCoalesce(): void { $this->access('offsetExists', '$x = $b["k"]["j"] ?? 1;'); }
	/** @return void executes the independent runtime reproduction */
	public function testPostIncMagic(): void {
		$this->check(['Magic' => 'public function __construct(private Folder $f) {} public function __get($n) { return 1; } public function __set($n, $v) { $this->f->newFile("s"); }',
			'Entry' => 'public function read(Folder $f): void { $m = new Magic($f); $m->x++; }']);
	}
	/** @return void executes the independent runtime reproduction */
	public function testCallStatic(): void {
		$this->check(['Magic' => 'public static ?Folder $f = null; public static function __callStatic($n, $a) { self::$f->newFile("s"); }',
			'Entry' => 'public function read(Folder $f): void { Magic::$f = $f; Magic::anything(); }']);
	}
	/** @return void executes the independent runtime reproduction */
	public function testPropertyHook(): void {
		$this->check(['Hooked' => 'public function __construct(private Folder $f) {} public string $x { get { $this->f->newFile("h"); return "a"; } }',
			'Entry' => 'public function read(Folder $f): void { $h = new Hooked($f); $x = $h->x; }']);
	}
	/** @return void executes the independent runtime reproduction */
	public function testUsortClosureCompare(): void {
		$this->text('$a = [$m, "b"]; usort($a, fn ($x, $y) => strcmp($x, $y));');
	}
	/** @return void executes the independent runtime reproduction */
	public function testExplicitToStringStringParamClosure(): void {
		$this->text('$g = function (string $s): void {}; $g($m);');
	}
}
