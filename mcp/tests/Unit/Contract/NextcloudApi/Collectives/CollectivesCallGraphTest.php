<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

/** The reader behind collectives-read-chains.json follows typed paths exactly: every body a call can land in is read. */
final class CollectivesCallGraphTest extends CallGraphTreeTestCase {
	/** @return void typed calls are followed exactly; a global class keeps its name; outside calls are leaves */
	public function testTypedPathsAreFollowedExactly(): void {
		$r = $this->reach([
			'Entry' => 'public function __construct(private Helper $helper, private Folder $root) {}
				public function read(): void { $b = $this->helper->builder(); $b->build(); $this->root->get("z"); \OC_Util::setupFS("a"); }',
			'Helper' => 'public function builder(): Builder { return new Builder(); }',
			'Builder' => 'public function build(): void {}',
		], 'Entry::read');
		$this->assertSame([], $r['unknown']);
		$this->assertSame(['OCA\\Collectives\\Service\\Builder::build', 'OCA\\Collectives\\Service\\Entry::read', 'OCA\\Collectives\\Service\\Helper::builder'],
			array_keys($r['methods']));
		$this->assertSame(['OCP\\Files\\Folder::get', 'OC_Util::setupFS'], $r['leaves'], 'a leading backslash names the global class, not one of the namespace');
	}

	/** @return void with no declared return, the type every return of the body has is the type: the write behind it is found */
	public function testAReturnWithNoDeclaredTypeIsReadFromItsBody(): void {
		$r = $this->reach([
			'Entry' => 'public function __construct(private Factory $factory) {}
				public function read(Folder $f): void { $w = $this->factory->make(); $w->persist($f); }',
			'Factory' => 'public function make() { return new Writer(); }',
			'Writer' => 'public function persist(Folder $f): void { $f->newFile("x"); }',
		], 'Entry::read');
		$this->assertSame([], $r['unknown']);
		$this->assertSame(['OCP\\Files\\Folder::newFile'], $r['leaves']);
	}

	/** @return void an include of the app's own data files is read; any other include is unknown */
	public function testOnlyAnIncludeOfDataFilesIsResolved(): void {
		mkdir($this->lib . '/Service/Words');
		file_put_contents($this->lib . '/Service/Words/en.php', "<?php\nreturn ['a', 'the'];\n");
		$data = $this->reach(['Entry' => 'public function read(string $l): array { return require __DIR__ . "/Words/" . $l . ".php"; }'], 'Entry::read');
		file_put_contents($this->lib . '/Service/Words/de.php', "<?php\nfile_put_contents('x', 'y');\nreturn [];\n");
		$code = $this->reach(['Entry' => 'public function read(string $l): array { return require __DIR__ . "/Words/" . $l . ".php"; }'], 'Entry::read');
		array_map('unlink', glob($this->lib . '/Service/Words/*.php'));
		rmdir($this->lib . '/Service/Words');
		$this->assertSame([], $data['unknown']);
		$this->assertUnknown($code['unknown'], 'Entry', 'include, eval or shell');
	}

	/** @return void Server::get(X::class) gives an X, whose calls are then leaves like any injected X */
	public function testServerGetOfAClassWrittenInPlaceIsAnX(): void {
		$r = $this->reach([
			'Entry' => 'public function read(): void { \\OCP\\Server::get(\\OCP\\L10N\\IFactory::class)->get("collectives")->t("x"); }',
		], 'Entry::read');
		$this->assertSame(['OCP\\L10N\\IFactory::get', 'OCP\\Server::get'], $r['leaves']);
		$this->assertUnknown($r['unknown'], 'Entry', '->get("collectives")->t(', 'with no type tree, the return of IFactory::get is not stated');
	}

	/** @return void R11: a constructor is followed, so a write in it is found */
	public function testAConstructorIsFollowed(): void {
		$r = $this->reach([
			'Entry' => 'public function read(): void { new Maker(); }',
			'Maker' => 'public function __construct(Folder $f) { $f->newFile("c"); }',
		], 'Entry::read');
		$this->assertArrayHasKey('OCA\\Collectives\\Service\\Maker::__construct', $r['methods']);
		$this->assertSame(['OCP\\Files\\Folder::newFile'], $r['leaves']);
	}

	/** @return void R11: a variable typed differently in two branches has both types, so the writing one is reached */
	public function testBranchesGiveTheUnionOfTypes(): void {
		$r = $this->reach([
			'Entry' => 'public function read(bool $x): void { if ($x) { $o = new Reader(); } else { $o = new Writer(); } $o->go(); }',
			'Reader' => 'public function go(): void {}',
			'Writer' => 'public function go(Folder $f): void { $f->newFile("w"); }',
		], 'Entry::read');
		$this->assertArrayHasKey('OCA\\Collectives\\Service\\Reader::go', $r['methods']);
		$this->assertArrayHasKey('OCA\\Collectives\\Service\\Writer::go', $r['methods']);
		$this->assertSame(['OCP\\Files\\Folder::newFile'], $r['leaves']);
	}

	/** @return void a call typed as a base class or interface also reaches every override in the app's subclasses */
	public function testACallReachesTheOverridesOfSubclasses(): void {
		$r = $this->reach([
			'Entry' => 'public function read(Base $b, \\OCP\\Search\\IProvider $p): void { $b->go(); $p->search(); }',
			'Base' => 'public function go(): void {}',
			'Child extends Base' => 'public function go(Folder $f): void { $f->newFile("o"); }',
			'Provider implements \\OCP\\Search\\IProvider' => 'public function search(Folder $f): void { $f->newFolder("p"); }',
		], 'Entry::read');
		$this->assertArrayHasKey('OCA\\Collectives\\Service\\Child::go', $r['methods']);
		$this->assertArrayHasKey('OCA\\Collectives\\Service\\Provider::search', $r['methods']);
		$this->assertSame(['OCP\\Files\\Folder::newFile', 'OCP\\Files\\Folder::newFolder', 'OCP\\Search\\IProvider::search'], $r['leaves']);
	}

	/** @return void R11: a call reaches every override, so its type is the union of their returns and the writing one is found */
	public function testAReturnIsTheUnionOverEveryOverride(): void {
		$r = $this->reach([
			'Entry' => 'public function read(Base $b, Folder $f): void { $x = $b->factory(); $x->persist($f); }',
			'Base' => 'public function factory() { return new Reader(); }',
			'Child extends Base' => 'public function factory() { return new Writer(); }',
			'Reader' => 'public function persist(Folder $f): void {}',
			'Writer' => 'public function persist(Folder $f): void { $f->newFile("w"); }',
		], 'Entry::read');
		$this->assertSame([], $r['unknown']);
		$this->assertArrayHasKey('OCA\\Collectives\\Service\\Writer::persist', $r['methods']);
		$this->assertSame(['OCP\\Files\\Folder::newFile'], $r['leaves']);
	}

	/** @return void a class built from a class constant of class names reaches each of them (the Wamania factory shape) */
	public function testANewOfAClassFromAConstantReachesEveryClassListed(): void {
		$r = $this->reach([
			'Entry' => 'public function read(string $c): void { Factory::create($c); }',
			'Factory' => 'const LANGS = [En::class => ["en"], De::class => ["de"]];
				public static function create(string $c) { foreach (self::LANGS as $classname => $codes) { if (in_array($c, $codes)) { return new $classname; } } }',
			'En' => 'public function __construct() {}',
			'De' => 'public function __construct(?Folder $f = null) { $f->newFile("d"); }',
		], 'Entry::read');
		$this->assertSame([], $r['unknown']);
		$this->assertArrayHasKey('OCA\\Collectives\\Service\\De::__construct', $r['methods']);
		$this->assertSame(['OCP\\Files\\Folder::newFile', 'function in_array'], $r['leaves']);
	}

	/** @return void a global function of a followed tree is followed, every definition of it */
	public function testAFunctionOfTheTreeIsFollowed(): void {
		file_put_contents($this->lib . '/Service/functions.php', '<?php function helper_x(\OCP\Files\Folder $f) { $f->newFile("q"); }');
		$r = $this->reach(['Entry' => 'public function read(Folder $f): void { \\helper_x($f); }'], 'Entry::read');
		$this->assertSame([], $r['unknown']);
		$this->assertArrayHasKey('function helper_x@Service/functions.php', $r['methods']);
		$this->assertSame(['OCP\\Files\\Folder::newFile'], $r['leaves']);
	}
}
