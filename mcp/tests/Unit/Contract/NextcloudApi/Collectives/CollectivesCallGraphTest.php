<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

use PHPUnit\Framework\TestCase;

/**
 * The reader behind collectives-read-chains.json, on a small source tree written here: it follows typed properties,
 * own and parent methods, statics, typed locals and returns, keeps calls that leave the app as leaves, and reaches a
 * write hidden behind a receiver whose type the source does not state.
 */
final class CollectivesCallGraphTest extends TestCase {
	private string $lib;

	/** @return void writes the tree */
	protected function setUp(): void {
		require_once __DIR__ . '/../../../../fixtures/nextcloud-api/collectives/CollectivesCallGraph.php';
		$this->lib = sys_get_temp_dir() . '/mcp-callgraph-' . bin2hex(random_bytes(4));
		mkdir($this->lib . '/Service', recursive: true);
		$files = [
			'Service/Base.php' => 'namespace OCA\\Collectives\\Service; class Base { protected function inherited(): void { Helper::stat(); } }',
			'Service/Helper.php' => 'namespace OCA\\Collectives\\Service; class Helper { public static function stat(): void { $w = new Builder(); $w->build(); } public function builder(): Builder { return new Builder(); } }',
			'Service/Builder.php' => 'namespace OCA\\Collectives\\Service; class Builder { public function build(): void { $this->writer(); } private function writer(): void { $folder->newFolder("x"); } }',
			'Service/Hidden.php' => 'namespace OCA\\Collectives\\Service; class Hidden { public function untypedTarget(): void { $f->newFile("y"); } }',
			'Service/Entry.php' => 'namespace OCA\\Collectives\\Service; use OCP\\Files\\IRootFolder; class Entry extends Base {'
				. ' public function __construct(private Helper $helper, private IRootFolder $root) {}'
				. ' public function read(): void { $this->inherited(); $b = $this->helper->builder(); $b->build(); $u = $this->root->getUserFolder("a"); $u->get("z"); }'
				. ' public function loose(array $items): void { foreach ($items as $item) { $item->untypedTarget(); } } }',
		];
		foreach ($files as $path => $source) {
			// One statement per line, as in the upstream sources the reader is made for.
			file_put_contents($this->lib . '/' . $path, "<?php\n" . preg_replace('/; (use|class) /', ";\n$1 ", $source) . "\n");
		}
	}

	/** @return void removes the tree */
	protected function tearDown(): void {
		array_map('unlink', glob($this->lib . '/Service/*.php'));
		rmdir($this->lib . '/Service');
		rmdir($this->lib);
	}

	/** @return void typed paths are followed exactly; a value from outside the app stays a leaf */
	public function testFollowsTypedCallsAndKeepsOutsideCallsAsLeaves(): void {
		$reached = (new \CollectivesCallGraph($this->lib))->reach(['OCA\\Collectives\\Service\\Entry::read']);
		$this->assertSame(['OCA\\Collectives\\Service\\Base::inherited', 'OCA\\Collectives\\Service\\Builder::build', 'OCA\\Collectives\\Service\\Builder::writer',
			'OCA\\Collectives\\Service\\Entry::read', 'OCA\\Collectives\\Service\\Helper::builder', 'OCA\\Collectives\\Service\\Helper::stat'], array_keys($reached['methods']));
		$this->assertContains('OCP\\Files\\IRootFolder::getUserFolder', $reached['leaves']);
		$this->assertContains('(outside value)::get', $reached['leaves'], 'a Files folder is not mistaken for a Collectives class with a get method');
		$this->assertSame(['OCA\\Collectives\\Service\\Builder::writer' => ['newFolder']], NativeWrites::in($reached['methods']), 'the write two calls away is found');
	}

	/** @return void a receiver without a stated type reaches every app method of that name, so nothing hides behind it */
	public function testAnUntypedReceiverIsOverApproximated(): void {
		$reached = (new \CollectivesCallGraph($this->lib))->reach(['OCA\\Collectives\\Service\\Entry::loose']);
		$this->assertArrayHasKey('OCA\\Collectives\\Service\\Hidden::untypedTarget', $reached['methods']);
		$this->assertSame(['OCA\\Collectives\\Service\\Hidden::untypedTarget' => ['newFile']], NativeWrites::in($reached['methods']));
	}
}
