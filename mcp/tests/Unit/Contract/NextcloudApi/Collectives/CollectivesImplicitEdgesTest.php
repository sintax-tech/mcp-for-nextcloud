<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

/** Implicit edges obey the same pinned-body boundary as explicit calls. */
final class CollectivesImplicitEdgesTest extends CallGraphTreeTestCase {
	/** @return void normal method names and static magic fallbacks both use PHP's case-insensitive dispatch */
	public function testOrdinaryAndStaticMagicNamesIgnoreCase(): void {
		$r = $this->reach([
			'Entry' => 'public function read(): void { (new Writer())->PERSIST(); Magic::missing(); }',
			'Writer' => 'public function persist(): void {}',
			'Magic' => 'public static function __CaLlStAtIc($name, $args) {}',
		], 'Entry::READ');
		$this->assertSame([], $r['unknown']);
		$this->assertArrayHasKey('OCA\\Collectives\\Service\\Writer::persist', $r['methods']);
		$this->assertArrayHasKey('OCA\\Collectives\\Service\\Magic::__CaLlStAtIc', $r['methods']);
	}

	/** @return void an absent method on an exception subclass is unknown, never an invented PHP-core method */
	public function testAnUndeclaredMethodWithACoreAncestorIsUnknown(): void {
		$r = $this->reach([
			'Magic extends \\RuntimeException' => '',
			'Entry' => 'public function read(): void { (new Magic())->anything(); }',
		], 'Entry::read');
		$this->assertUnknown($r['unknown'], 'Magic', 'Magic::anything is declared nowhere');
		$this->assertNotContains('RuntimeException::anything', $r['leaves']);
	}

	/** @return void a known inherited internal hook is identified as PHP-core, without calling it */
	public function testInheritedCoreStringConversionIsACoreLeaf(): void {
		$r = $this->reach([
			'Message extends \\RuntimeException' => '',
			'Entry' => 'public function read(): void { $m = new Message(); $x = ""; $x .= $m; }',
		], 'Entry::read');
		$this->assertSame([], $r['unknown']);
		$this->assertContains('php-core Exception::__toString', $r['leaves']);
	}

	/** @return void serialize follows both possible serialization hooks conservatively */
	public function testSerializationFollowsThePinnedHooks(): void {
		$r = $this->reach([
			'Payload' => 'public function __serialize(): array { return []; } public function __sleep(): array { return []; }',
			'Entry' => 'public function read(): void { serialize(new Payload()); }',
		], 'Entry::read');
		$this->assertSame([], $r['unknown']);
		$this->assertArrayHasKey('OCA\\Collectives\\Service\\Payload::__serialize', $r['methods']);
		$this->assertArrayHasKey('OCA\\Collectives\\Service\\Payload::__sleep', $r['methods']);
	}

	/** @return void unserialize follows available wakeup hooks but refuses an unconstrained serialized class */
	public function testUnserializationDoesNotGuessTheRuntimeClass(): void {
		$r = $this->reach([
			'Payload' => 'public function __unserialize(array $data): void {} public function __wakeup(): void {}',
			'Entry' => 'public function read(string $data): void { unserialize($data); }',
		], 'Entry::read');
		$this->assertUnknown($r['unknown'], 'Entry', 'unserialize of a class not statically established');
		$this->assertArrayHasKey('OCA\\Collectives\\Service\\Payload::__unserialize', $r['methods']);
		$this->assertArrayHasKey('OCA\\Collectives\\Service\\Payload::__wakeup', $r['methods']);
	}

	/** @return void self construction uses the same conservative constructor union as static construction */
	public function testSelfConstructionFollowsEverySubclassConstructor(): void {
		$r = $this->reach([
			'Factory' => 'public function __construct() {} public static function make() { return new self(); }',
			'Child extends Factory' => 'public function __construct(Folder $f) { $f->newFile("child"); }',
			'Entry' => 'public function read(): void { Factory::make(); }',
		], 'Entry::read');
		$this->assertArrayHasKey('OCA\\Collectives\\Service\\Child::__construct', $r['methods']);
		$this->assertContains('OCP\\Files\\Folder::newFile', $r['leaves']);
	}

	/** @return void an implicit destination with pinned external source is followed, including the write it hides */
	public function testAnExternalPinnedMagicBodyIsFollowed(): void {
		$this->reach(['Entry extends \\Pinned\\Base' => 'public function read(): void { $this->anything(); }'], 'Entry::read');
		mkdir($this->lib . '/types');
		file_put_contents($this->lib . '/types/Folder.php', '<?php namespace OCP\\Files; class Folder { public function newFile(string $name): void {} }');
		file_put_contents($this->lib . '/types/Base.php', '<?php namespace Pinned; class Base {
			public function __CALL($name, $args) { (new \\OCP\\Files\\Folder())->newFile("external"); }
		}');
		$r = (new \CollectivesCallGraph($this->lib . '/Service', $this->lib . '/types'))->reach(['OCA\\Collectives\\Service\\Entry::read']);
		$this->assertSame([], $r['unknown']);
		$this->assertArrayHasKey('Pinned\\Base::__CALL', $r['methods']);
		$this->assertContains('OCP\\Files\\Folder::newFile', $r['leaves']);
	}
}
