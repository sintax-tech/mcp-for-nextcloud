<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Collectives;

/** The syntax and native-function boundaries are positive lists; neither boundary has a permissive default. */
final class CollectivesClosedSubsetTest extends CallGraphTreeTestCase {
	/** @return void a completely new unsupported syntax node is rejected even if its value is unused */
	public function testAnUnlistedNodeAlwaysReportsItsLocation(): void {
		$r = $this->reach(['Entry' => 'public function read() { yield 1; }'], 'Entry::read');
		$this->assertUnknown($r['unknown'], 'Entry', 'AST node outside the closed subset: PhpParser\\Node\\Expr\\Yield_');
	}

	/** @return void a function without an indexed body or native model cannot acquire a scalar return by default */
	public function testAnUnlistedFunctionNeverDefaultsToScalar(): void {
		$r = $this->reach(['Entry' => 'public function read() { foreach (unknown_source() as $value) { $s = ""; $s .= $value; } }'], 'Entry::read');
		$this->assertUnknown($r['unknown'], 'Entry', 'function outside the closed subset: unknown_source');
		$this->assertUnknown($r['unknown'], 'Entry', 'implicit receiver of unknown type');
	}

	/** @return void references and an unproved spread do not become accepted merely because their node class is listed */
	public function testUnsupportedNodeModifiersRemainUnknown(): void {
		$r = $this->reach(['Entry' => 'public function read($items) { $copy =& $items; implode("", [...$items]); }'], 'Entry::read');
		$this->assertUnknown($r['unknown'], 'Entry', 'AST node outside the closed subset: PhpParser\\Node\\Expr\\AssignRef');
		$this->assertUnknown($r['unknown'], 'Entry', 'spread without a proven array type');
	}

	/** @return void every native model explicitly names its return and its implicit-call behavior */
	public function testTheListsAreExplicitAndHaveNoDefaultEntry(): void {
		$dir = __DIR__ . '/../../../../fixtures/nextcloud-api/collectives/';
		$nodes = json_decode(file_get_contents($dir . 'collectives-ast-subset.json'), true, 512, JSON_THROW_ON_ERROR);
		$functions = json_decode(file_get_contents($dir . 'collectives-php-functions.json'), true, 512, JSON_THROW_ON_ERROR);
		$this->assertNotContains('PhpParser\\Node\\Expr\\Match_', $nodes);
		$this->assertArrayNotHasKey('func_get_args', $functions);
		$this->assertNull(\CallGraphFunctions::model('not_listed'));
		foreach ($nodes as $node) {
			$this->assertTrue(class_exists($node), $node);
		}
		foreach ($functions as $name => $model) {
			$this->assertContains($model['effect'], ['none', 'stringify', 'callback', 'serialize', 'unserialize'], $name);
			$this->assertNotSame('', $model['return'], $name);
			$this->assertArrayHasKey('arguments', $model);
		}
	}
	/** @return void an app function shadowing a builtin is followed instead of acquiring the builtin's scalar model */
	public function testNamespacedBuiltinShadowIsFollowed(): void {
		file_put_contents($this->lib . '/Service/Shadow.php', '<?php namespace OCA\\Collectives\\Service;
			function json_decode($text, $assoc) { return mystery(); }
			class Entry { public function read(): void { strval(JSON_DECODE("[]", true)); } }');
		$r = (new \CollectivesCallGraph($this->lib))->reach(['OCA\\Collectives\\Service\\Entry::read']);
		$this->assertNotSame([], $r['unknown']);
		$this->assertContains('function mystery', $r['leaves']);
	}

}
