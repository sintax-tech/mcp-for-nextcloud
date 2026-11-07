<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

/** Declaration coercion is modeled at every invocation boundary rather than by erasing the caller's object type. */
final class CollectivesCoercionEdgesTest extends RuntimeTreeTestCase {
	/** @return void PHP's internal array callback invocation also coerces typed string arguments */
	public function testTypedCallbackArgumentsDispatchStringConversion(): void {
		$this->runtimeAndUnknown([
			'Text' => 'public function __construct(private Folder $f) {} public function __toString(): string { $this->f->newFile("callback"); return "a"; }',
			'Entry' => 'public function read(Folder $f): void { array_map(fn (string $s) => $s, [new Text($f)]); }',
		]);
	}

	/** @return void typed arrow return coercion is independent of parameter coercion */
	public function testTypedArrowReturnDispatchesStringConversion(): void {
		$this->runtimeAndUnknown([
			'Text' => 'public function __construct(private Folder $f) {} public function __toString(): string { $this->f->newFile("arrow"); return "a"; }',
			'Entry' => 'public function read(Folder $f): void { array_map(fn ($s): string => $s, [new Text($f)]); }',
		]);
	}

	/** @return void an invokable object's native signature has the same coercion rules as an ordinary method */
	public function testInvokableStringParameterDispatchesConversion(): void {
		$this->runtimeAndUnknown([
			'Text' => 'public function __construct(private Folder $f) {} public function __toString(): string { $this->f->newFile("invoke"); return "a"; }',
			'Sink' => 'public function __invoke(string $value): void {}',
			'Entry' => 'public function read(Folder $f): void { $sink = new Sink(); $sink(new Text($f)); }',
		]);
	}

	/** @return void a dynamic property name still observes the class's declared native string property */
	public function testDynamicTypedPropertyDispatchesConversion(): void {
		$this->runtimeAndUnknown([
			'Text' => 'public function __construct(private Folder $f) {} public function __toString(): string { $this->f->newFile("property"); return "a"; }',
			'Entry' => 'private string $s = ""; public function read(Folder $f): void { $field = "s"; $this->$field = new Text($f); }',
		]);
	}

	/** @return void strict declarations throw for incompatible objects rather than dispatching weak string conversion */
	public function testStrictStringDeclarationDoesNotInventConversion(): void {
		file_put_contents($this->lib . '/Service/Strict.php', '<?php declare(strict_types=1); namespace Example;
			class Text { public function __toString(): string { return "a"; } }
			class Entry { public function read(): void { $this->sink(new Text()); } private function sink(string $value): void {} }');
		$r = (new \CollectivesCallGraph($this->lib))->reach(['Example\\Entry::read']);
		$this->assertArrayNotHasKey('Example\\Text::__toString', $r['methods']);
	}

	/** @return void nested patterns and loop destinations enforce property strings too */
	public function testStructuredPropertyDestinationsCoerceObjects(): void {
		foreach (['[$this->s] = [$m];', 'foreach ([$m] as $this->s) {}', '[["v" => $this->s]] = [["v" => $m]];', 'foreach ([[$m]] as [$this->s]) {}'] as $write) {
			$this->runtimeAndUnknown([
				'Text' => 'public function __construct(private Folder $f) {} public function __toString(): string { $this->f->newFile("target"); return "a"; }',
				'Entry' => 'private string $s; public function read(Folder $f): void { $m = new Text($f); ' . $write . ' }',
			]);
		}
	}

	/** @return void a cast container cannot hide a stored object behind primitive evidence */
	public function testArrayCastCannotEraseObjectFields(): void {
		$this->runtimeAndUnknown([
			'Text' => 'public function __construct(private Folder $f) {} public function __toString(): string { $this->f->newFile("cast"); return "a"; }',
			'Pair' => 'public function __construct(public mixed $value) {}',
			'Entry' => 'public function read(Folder $f): void { foreach ((array)new Pair(new Text($f)) as $value) { strval($value); } }',
		]);
	}

	/** @return void destination traversal also follows magic setters and ArrayAccess writes */
	public function testStructuredProtocolDestinationsWrite(): void {
		foreach (['[$b->x] = [1];', 'foreach ([1] as $b->x) {}', '[$b["x"]] = [1];', 'foreach ([1] as $b["x"]) {}'] as $write) {
			$this->runtimeAndUnknown([
				'Bag implements \\ArrayAccess' => 'public function __construct(private Folder $f) {}
					public function __set($n, $v): void { $this->f->newFile("set"); }
					public function offsetSet(mixed $k, mixed $v): void { $this->f->newFile("offset"); }
					public function offsetExists(mixed $k): bool { return false; }
					public function offsetGet(mixed $k): mixed { return null; }
					public function offsetUnset(mixed $k): void {}',
				'Entry' => 'public function read(Folder $f): void { $b = new Bag($f); ' . $write . ' }',
			]);
		}
	}
}
