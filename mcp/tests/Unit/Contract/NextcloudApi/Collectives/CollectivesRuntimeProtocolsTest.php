<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

/** Round eight: admitted comparisons, builtins and object protocols must expose their actual runtime calls. */
final class CollectivesRuntimeProtocolsTest extends RuntimeTreeTestCase {
	/**
	 * @param string $use exact PHP expression or statement
	 * @param int $writes expected runtime write count
	 * @return array graph of the same source
	 */
	private function text(string $use, int $writes = 1): array {
		return $this->runtimeAndUnknown([
			'Text' => 'public function __construct(private Folder $f) {}
				public function __toString(): string { $this->f->newFile("convert"); return "a"; }',
			'Entry' => 'public function read(Folder $f): void { $m = new Text($f); ' . $use . ' }',
		], $writes);
	}

	/** @return void all loose comparison operators and both sides of switch/case dispatch __toString */
	public function testLooseComparisonsAndSwitchWriteOnceAndAreFollowed(): void {
		foreach (['==', '!=', '<', '>', '<=', '>=', '<=>'] as $operator) {
			$r = $this->text('$x = $m ' . $operator . ' "a";');
			$this->assertArrayHasKey('OCA\\Collectives\\Service\\Text::__toString', $r['methods'], $operator);
		}
		foreach (['switch ($m) { case "a": break; }', 'switch ("a") { case $m: break; }'] as $statement) {
			$r = $this->text($statement);
			$this->assertArrayHasKey('OCA\\Collectives\\Service\\Text::__toString', $r['methods']);
		}
	}

	/** @return void identity and a literal strict=true do not request a conversion that PHP never makes */
	public function testStrictComparisonsDoNotInventStringConversion(): void {
		foreach (['$x = $m === "a";', '$x = $m !== "a";', 'in_array($m, ["a"], true);',
			'array_search($m, ["a"], true);', 'array_keys([$m], "a", true);'] as $statement) {
			$r = $this->text($statement, 0);
			$this->assertArrayNotHasKey('OCA\\Collectives\\Service\\Text::__toString', $r['methods']);
		}
	}

	/** @return void comparison, sorting, string keys and overlooked typed string arguments all expose the write */
	public function testBuiltinConversionsWriteOnceAndAreFollowed(): void {
		foreach (['in_array($m, ["a"]);', 'array_search($m, ["a"]);', 'max($m, "b");', 'min([$m, "b"]);',
			'$items = [$m, "b"]; sort($items);', 'array_unique([$m, "b"]);', 'array_keys([$m], "a");',
			'array_column([["id" => 1]], $m);', 'array_column([["id" => 1, "a" => 2]], "id", $m);',
			'array_combine([$m], [1]);', 'property_exists(new \\stdClass(), $m);'] as $statement) {
			$r = $this->text($statement);
			$this->assertArrayHasKey('OCA\\Collectives\\Service\\Text::__toString', $r['methods'], $statement);
		}
	}

	/** @return void PHP 8.2 keeps the graph's extra string-conversion edge conservative; newer runtimes execute it. */
	public function testRangeConversionIsConservativeOnEverySupportedPhpVersion(): void {
		$legacy = PHP_VERSION_ID < 80300;
		$r = $this->runtimeAndUnknown([
			'Text' => 'public function __construct(private Folder $f) {}
				public function __toString(): string { $this->f->newFile("convert"); return "a"; }',
			'Entry' => 'public function read(Folder $f): void { $m = new Text($f); range($m, "b"); }',
		], $legacy ? 0 : 1, false, $legacy ? 'Object of class OCA\\Collectives\\Service\\Text could not be converted to int' : '');
		$this->assertArrayHasKey('OCA\\Collectives\\Service\\Text::__toString', $r['methods']);
		$this->assertContains('OCP\\Files\\Folder::newFile', $r['leaves']);
		$this->assertSame([], $r['unknown']);
	}

	/** @return void Countable::count is an ordinary followed body rather than an opaque function leaf */
	public function testCountableWritesOnceAndIsFollowed(): void {
		$r = $this->runtimeAndUnknown([
			'Bag implements \\Countable' => 'public function __construct(private Folder $f) {}
				public function count(): int { $this->f->newFile("count"); return 1; }',
			'Entry' => 'public function read(Folder $f): void { count(new Bag($f)); }',
		]);
		$this->assertArrayHasKey('OCA\\Collectives\\Service\\Bag::count', $r['methods']);
	}

	/** @return void IteratorAggregate::getIterator runs before the first loop body, even for an empty iterator */
	public function testAggregateWritesOnceAndIsFollowed(): void {
		$r = $this->runtimeAndUnknown([
			'Bag implements \\IteratorAggregate' => 'public function __construct(private Folder $f) {}
				public function getIterator(): \\Traversable { $this->f->newFile("iterate"); return new \\ArrayIterator([]); }',
			'Entry' => 'public function read(Folder $f): void { foreach (new Bag($f) as $item) {} }',
		]);
		$this->assertArrayHasKey('OCA\\Collectives\\Service\\Bag::getIterator', $r['methods']);
	}

	/** @return void all five Iterator callbacks are visited; each chosen callback writes exactly once */
	public function testEveryIteratorCallbackIsFollowed(): void {
		$methods = ['current' => 'return "v";', 'next' => '$this->i++;', 'key' => 'return "k";',
			'valid' => 'return $this->i < 1;', 'rewind' => '$this->i = 0;'];
		foreach (array_keys($methods) as $writing) {
			$body = 'private int $i = 0; public function __construct(private Folder $f) {}';
			foreach ($methods as $method => $code) {
				$type = match ($method) { 'current', 'key' => 'string', 'valid' => 'bool', default => 'void' };
				$write = $writing === $method ? ($method === 'valid' ? 'if ($this->i === 0) { $this->f->newFile("valid"); }' : '$this->f->newFile("iterator");') : '';
				$body .= ' public function ' . $method . '(): ' . $type . ' { ' . $write . $code . ' }';
			}
			$r = $this->runtimeAndUnknown(['Cursor implements \\Iterator' => $body,
				'Entry' => 'public function read(Folder $f): void { foreach (new Cursor($f) as $key => $value) {} }']);
			foreach (array_keys($methods) as $method) {
				$this->assertArrayHasKey('OCA\\Collectives\\Service\\Cursor::' . $method, $r['methods']);
			}
		}
	}

	/** @return void a Traversable declaration must not hide the concrete iterator returned by an aggregate body */
	public function testAggregateFollowsTheReturnedIteratorBodies(): void {
		$r = $this->runtimeAndUnknown([
			'Cursor implements \\Iterator' => 'private int $i = 0; public function __construct(private Folder $f) {}
				public function current(): string { $this->f->newFile("current"); return "v"; }
				public function key(): string { return "k"; } public function valid(): bool { return $this->i < 1; }
				public function next(): void { $this->i++; } public function rewind(): void { $this->i = 0; }',
			'Bag implements \\IteratorAggregate' => 'public function __construct(private Folder $f) {}
				public function getIterator(): \\Traversable { return new Cursor($this->f); }',
			'Entry' => 'public function read(Folder $f): void { foreach (new Bag($f) as $value) {} }',
		]);
		foreach (['current', 'next', 'key', 'valid', 'rewind'] as $method) {
			$this->assertArrayHasKey('OCA\\Collectives\\Service\\Cursor::' . $method, $r['methods']);
		}
	}

	/** @return void ArrayAccess dispatch depends on the parent context of the index expression */
	public function testEveryArrayAccessContextWritesOnceAndIsFollowed(): void {
		foreach (['$x = $b["k"];' => 'offsetGet', 'isset($b["k"]);' => 'offsetExists',
			'empty($b["k"]);' => 'offsetExists', '$x = $b["k"] ?? 1;' => 'offsetExists',
			'$b["k"] = 1;' => 'offsetSet', 'unset($b["k"]);' => 'offsetUnset'] as $use => $writing) {
			$body = 'public function __construct(private Folder $f) {}';
			foreach (['offsetGet' => 'mixed $key): mixed', 'offsetExists' => 'mixed $key): bool',
				'offsetSet' => 'mixed $key, mixed $value): void', 'offsetUnset' => 'mixed $key): void'] as $method => $signature) {
				$write = $method === $writing ? '$this->f->newFile("offset");' : '';
				$return = match ($method) { 'offsetGet' => 'return 1;', 'offsetExists' => 'return false;', default => '' };
				$body .= 'public function ' . $method . '(' . $signature . '{' . $write . $return . '}';
			}
			$r = $this->runtimeAndUnknown(['Bag implements \\ArrayAccess' => $body,
				'Entry' => 'public function read(Folder $f): void { $b = new Bag($f); ' . $use . ' }']);
			$this->assertArrayHasKey('OCA\\Collectives\\Service\\Bag::' . $writing, $r['methods'], $use);
		}
	}
}
