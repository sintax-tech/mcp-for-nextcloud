<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

/** Every shape the reader cannot resolve with certainty is reported with its file and line, never taken as safe. */
final class CollectivesCallGraphFormsTest extends CallGraphTreeTestCase {
	/** @return void R11: a call through an untyped property is unknown, never safe (Writer::persist) */
	public function testAnUntypedPropertyIsUnknown(): void {
		$r = $this->reach([
			'Entry' => 'private $writer;
				public function read(): void { $this->writer->persist(); }',
			'Writer' => 'public function persist(Folder $f): void { $f->newFile("x"); }',
		], 'Entry::read');
		$this->assertUnknown($r['unknown'], 'Entry', 'untyped property $this->writer->persist');
	}

	/** @return void R11: a foreach item hides its type, so the hidden call is unknown */
	public function testAForeachItemIsUnknown(): void {
		$r = $this->reach([
			'Entry' => 'public function read(array $items): void { foreach ($items as $item) { $item->hidden(); } }',
			'Hidden' => 'public function hidden(Folder $f): void { $f->newFile("y"); }',
		], 'Entry::read');
		$this->assertUnknown($r['unknown'], 'Entry', 'untyped value $item->hidden');
	}

	/** @return void R11: a factory with no declared return that hands back an untyped value gives a value of unknown type */
	public function testAnUntypedReturnIsUnknown(): void {
		$r = $this->reach([
			'Entry' => 'public function __construct(private Factory $factory) {}
				public function read(): void { $w = $this->factory->make(); $w->persist(); }',
			'Factory' => 'private $writer;
				public function make() { return $this->writer; }',
		], 'Entry::read');
		$this->assertUnknown($r['unknown'], 'Entry', 'untyped value $w->persist');
	}

	/** @return void a class of the app missing from the tree is unknown, never a leaf outside the app */
	public function testAMissingAppClassIsUnknown(): void {
		$r = $this->reach(['Entry' => 'public function read(): void { $w = new Writer(); $w->persist(); }'], 'Entry::read');
		$this->assertUnknown($r['unknown'], 'Entry', 'Writer::persist names a class of a followed tree the tree does not have');
	}

	/** @return void R11: container and Server lookups, callables, closure parameters and dynamic names are unknown */
	public function testLookupsCallablesAndDynamicNamesAreUnknown(): void {
		$r = $this->reach([
			'Entry' => 'public function __construct(private \\OCP\\IServerContainer $server) {}
				public function read(string $name): void {
				$this->container->get(Writer::class);
				$this->server->get(Writer::class);
				\\OCP\\Server::get($name);
				call_user_func([$this, "x"]);
				array_map(fn ($p) => $p->go(), []);
				$m = "persist";
				$this->$m();
			}',
		], 'Entry::read');
		foreach (['untyped property $this->container->get(', 'container lookup $this->server->get(', 'Server::get( of a class not written in place',
			'call_user_func( with a callable', 'untyped value $p->go(', 'dynamic method name'] as $form) {
			$this->assertUnknown($r['unknown'], 'Entry', $form);
		}
	}

	/** @return void R12: an event dispatched to listeners is unknown, whatever the listener does */
	public function testADispatchedEventIsUnknown(): void {
		$r = $this->reach([
			'Entry' => 'public function __construct(private IEventDispatcher $dispatcher) {}
				public function read(): void { $this->dispatcher->dispatchTyped(new Event()); }',
			'Listener' => 'public function handle(Folder $f): void { $f->newFolder("z"); }',
		], 'Entry::read');
		$this->assertUnknown($r['unknown'], 'Entry', 'dispatchTyped(');
	}

	/** @return void R11: a method no app class declares, on an app class with no outside parent, is magic: unknown */
	public function testMagicCallIsUnknown(): void {
		$r = $this->reach([
			'Entry' => 'public function read(): void { $e = new Magic(); $e->anything(); }',
			'Magic' => 'public function __call($n, $a) { return null; }',
		], 'Entry::read');
		$this->assertUnknown($r['unknown'], 'Magic', 'Magic::anything is declared nowhere');
	}

	/** @return void R11: an override whose return has no single type makes the call's value unknown */
	public function testAnOverrideWithoutASingleReturnIsUnknown(): void {
		$r = $this->reach([
			'Entry' => 'public function read(Base $b): void { $x = $b->factory(); $x->persist(); }',
			'Base' => 'public function factory(): Reader { return new Reader(); }',
			'Child extends Base' => 'private $hidden;
				public function factory(): Reader { return $this->pick(); }
				private function pick() { return $this->hidden; }',
			'Reader' => 'public function persist(): void {}',
		], 'Entry::read');
		$this->assertSame([], $r['unknown'], 'a declared return is the type of every override');
		$r = $this->reach([
			'Entry' => 'public function read(Base $b): void { $x = $b->factory(); $x->persist(); }',
			'Base' => 'public function factory() { return new Reader(); }',
			'Child extends Base' => 'private $hidden;
				public function factory() { return $this->hidden; }',
		], 'Entry::read');
		$this->assertUnknown($r['unknown'], 'Entry', 'untyped value $x->persist(');
	}

	/** @return void R11: a property access on a class declaring __get (or a value of unknown type) is unknown */
	public function testAPropertyThatMayRunGetIsUnknown(): void {
		$r = $this->reach([
			'Entry' => 'public function read(): void { $m = new Magic(); $x = $m->anything; }',
			'Magic' => 'public function __get($n) { return null; }',
		], 'Entry::read');
		$this->assertUnknown($r['unknown'], 'Entry', 'property access that may run __get, __set, __isset or __unset $m->anything');
	}

	/** @return void inherited property hooks are refused for writes, isset and unset as well as reads */
	public function testInheritedPropertyHooksAreUnknown(): void {
		foreach ([['__set($n, $v)', '$m->x = 1;'], ['__isset($n)', 'isset($m->x);'], ['__unset($n)', 'unset($m->x);']] as [$hook, $use]) {
			$r = $this->reach([
				'Entry' => 'public function read(): void { $m = new Child(); ' . $use . ' }',
				'Magic' => 'public function ' . $hook . ' {}',
				'Child extends Magic' => '',
			], 'Entry::read');
			$this->assertUnknown($r['unknown'], 'Entry', 'property access that may run __get, __set, __isset or __unset');
		}
	}

	/** @return void R11: a clone of a class declaring __clone is unknown */
	public function testACloneThatMayRunCloneIsUnknown(): void {
		$r = $this->reach([
			'Entry' => 'public function read(): void { $c = new Copied(); $d = clone $c; }',
			'Copied' => 'public function __clone() {}',
		], 'Entry::read');
		$this->assertUnknown($r['unknown'], 'Entry', 'clone that may run __clone clone $c');
	}

	/** @return void R11: calling an object declaring __invoke is unknown, like any callable value */
	public function testAnInvokedObjectIsUnknown(): void {
		$r = $this->reach([
			'Entry' => 'public function read(): void { $h = new Handler(); $h(); }',
			'Handler' => 'public function __invoke() {}',
		], 'Entry::read');
		$this->assertUnknown($r['unknown'], 'Entry', 'call of a callable value $h(');
	}

	/** @return void R11: a string use of a class declaring __toString is unknown */
	public function testAStringUseThatMayRunToStringIsUnknown(): void {
		$r = $this->reach([
			'Entry' => 'public function read(): void { $s = new Text(); $t = "x" . $s; }',
			'Text' => 'public function __toString(): string { return ""; }',
		], 'Entry::read');
		$this->assertUnknown($r['unknown'], 'Entry', 'string use that may run __toString $s');
	}
}
