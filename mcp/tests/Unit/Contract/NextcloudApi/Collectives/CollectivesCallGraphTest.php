<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

use PHPUnit\Framework\TestCase;

/**
 * The reader behind collectives-read-chains.json, on small source trees written here. It is closed: typed paths are
 * followed exactly (constructors and every branch of a union included), and every shape it cannot resolve with
 * certainty is reported with its file and line instead of being taken as safe.
 */
final class CollectivesCallGraphTest extends TestCase {
	private string $lib;

	/** @return void loads the reader and makes an empty tree */
	protected function setUp(): void {
		require_once __DIR__ . '/../../../../fixtures/nextcloud-api/collectives/CollectivesCallGraph.php';
		$this->lib = sys_get_temp_dir() . '/mcp-callgraph-' . bin2hex(random_bytes(4));
		mkdir($this->lib . '/Service', recursive: true);
	}

	/** @return void removes the tree */
	protected function tearDown(): void {
		array_map('unlink', glob($this->lib . '/Service/*.php'));
		rmdir($this->lib . '/Service');
		rmdir($this->lib);
	}

	/**
	 * @param array<string, string> $classes class body by short name, all in OCA\Collectives\Service
	 * @param string $entry `Class::method` to start from
	 * @return array{methods:array<string,string>, leaves:list<string>, unknown:list<string>} what the reader reached
	 */
	private function reach(array $classes, string $entry): array {
		$parents = ['Child' => ' extends Base', 'Provider' => ' implements \\OCP\\Search\\IProvider'];
		foreach ($classes as $name => $body) {
			file_put_contents($this->lib . '/Service/' . $name . '.php', "<?php\nnamespace OCA\\Collectives\\Service;\n"
				. "use OCP\\Files\\Folder;\nuse OCP\\EventDispatcher\\IEventDispatcher;\nclass $name" . ($parents[$name] ?? '') . " {\n$body\n}\n");
		}
		return (new \CollectivesCallGraph($this->lib))->reach(['OCA\\Collectives\\Service\\' . $entry]);
	}

	/**
	 * @param list<string> $unknown what the reader reported
	 * @param string $file the short name of the file it is reported in
	 * @param string $form the form expected among them
	 * @return void
	 */
	private function assertUnknown(array $unknown, string $file, string $form): void {
		$hits = array_filter($unknown, static fn (string $u): bool => str_contains($u, $form));
		$this->assertNotSame([], $hits, "$form is reported: " . implode(' | ', $unknown));
		$this->assertMatchesRegularExpression('/^Service\/' . $file . '\.php:\d+ /', reset($hits), 'with its file and line');
	}

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

	/** @return void a class of the app missing from the tree is unknown, never a leaf outside the app */
	public function testAMissingAppClassIsUnknown(): void {
		$r = $this->reach(['Entry' => 'public function read(): void { $w = new Writer(); $w->persist(); }'], 'Entry::read');
		$this->assertUnknown($r['unknown'], 'Entry', 'Writer::persist names a class of the app the tree does not have');
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

	/** @return void Server::get(X::class) gives an X, whose calls are then leaves like any injected X */
	public function testServerGetOfAClassWrittenInPlaceIsAnX(): void {
		$r = $this->reach([
			'Entry' => 'public function read(): void { \\OCP\\Server::get(\\OCP\\L10N\\IFactory::class)->get("collectives")->t("x"); }',
		], 'Entry::read');
		$this->assertSame(['OCP\\L10N\\IFactory::get', 'OCP\\Server::get'], $r['leaves']);
		$this->assertUnknown($r['unknown'], 'Entry', '->get("collectives")->t(', 'with no type tree, the return of IFactory::get is not stated');
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
			'Child' => 'public function go(Folder $f): void { $f->newFile("o"); }',
			'Provider' => 'public function search(Folder $f): void { $f->newFolder("p"); }',
		], 'Entry::read');
		$this->assertArrayHasKey('OCA\\Collectives\\Service\\Child::go', $r['methods']);
		$this->assertArrayHasKey('OCA\\Collectives\\Service\\Provider::search', $r['methods']);
		$this->assertSame(['OCP\\Files\\Folder::newFile', 'OCP\\Files\\Folder::newFolder', 'OCP\\Search\\IProvider::search'], $r['leaves']);
	}

	/** @return void R11: a method no app class declares, on an app class with no outside parent, is magic: unknown */
	public function testMagicCallIsUnknown(): void {
		$r = $this->reach([
			'Entry' => 'public function read(): void { $e = new Magic(); $e->anything(); }',
			'Magic' => 'public function __call($n, $a) { return null; }',
		], 'Entry::read');
		$this->assertUnknown($r['unknown'], 'Magic', 'Magic::anything is declared nowhere');
	}
}
