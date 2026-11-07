<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts\Dev;

use OCA\Mcp\Scripts\Dev\FqcnMap;
use OCA\Mcp\Scripts\Dev\MoveEquivalence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../scripts/dev/bootstrap.php';

/** Keeps the reviewer's 23-case control matrix executable after future path-check changes. */
final class ReviewMatrixTest extends TestCase {
	/**
	 * Semantic edits fail; the documented PHPDoc-only edit passes.
	 *
	 * @param string $before old body
	 * @param string $after new body
	 * @param bool $passes expected equivalence
	 */
	#[DataProvider('edits')]
	public function testReviewMatrix(string $before, string $after, bool $passes = false): void {
		$map = new FqcnMap([], 'Acme\\', 'src/');
		$problems = (new MoveEquivalence(['Caller.php' => '<?php ' . $before], ['Caller.php' => '<?php ' . $after], $map))->check();
		$this->assertSame($passes, $problems === [], implode("\n", $problems));
	}

	/**
	 * @return iterable<string,array{string,string,2?:bool}> the 20 non-movement controls
	 */
	public static function edits(): iterable {
		yield '01 function import' => ['use function A\\f; f();', 'use function B\\f; f();'];
		yield '02 const import' => ['use const A\\C; echo C;', 'use const B\\C; echo C;'];
		yield '03 group import' => ['use A\\{C}; new C;', 'use B\\{C}; new C;'];
		yield '04 instanceof' => ['$x instanceof \\A\\C;', '$x instanceof \\B\\C;'];
		yield '05 new' => ['new \\A\\C;', 'new \\B\\C;'];
		yield '06 static call' => ['\\A\\C::run();', '\\B\\C::run();'];
		yield '07 attribute' => ['use A\\Guard; #[Guard] class C {}', 'use B\\Guard; #[Guard] class C {}'];
		yield '08 attribute args' => ["use A\\Guard; #[Guard(action: 'x')] class C {}", "use B\\Guard; #[Guard(action: 'x')] class C {}"];
		yield '09 PHPDoc' => ['/** @var A\\C */ $x = 1;', '/** @var B\\C */ $x = 1;', true];
		yield '10 class_exists' => ["class_exists('A\\C');", "class_exists('B\\C');"];
		yield '11 partial string' => ["echo 'A\\C::run';", "echo 'B\\C::run';"];
		yield '12 heredoc' => ["echo <<<TXT\na \$x\nTXT;", "echo <<<TXT\n a \$x\nTXT;"];
		yield '13 interpolation' => ['echo "a $x";', 'echo " a $x";'];
		yield '14 escape' => ['echo "\\q";', 'echo "q";'];
		yield '15 namespace block' => ['namespace A { class C {} }', 'namespace B { class C {} }'];
		yield '16 second class' => ['namespace A; class C {} class D { function f() { return new \\X\\C; } }', 'namespace A; class C {} class D { function f() { return new \\Y\\C; } }'];
		yield '18 mixed function group' => ['use A\\{function f}; f();', 'use A\\{function g as f}; f();'];
		yield '19 constant/class alias' => ['use A\\FLAG; use const B\\FLAG; echo FLAG;', 'use A\\FLAG; use const C\\FLAG; echo FLAG;'];
		yield '20 namespace function alias' => ['use A\\Ns; Ns\\f();', 'use B\\Ns; Ns\\f();'];
		yield '21 free function namespace' => ['namespace A; function f() {}', 'namespace B; function f() {}'];
	}

	/** Two declarations in one file follow the mapped companion chain (case 17). */
	public function testCompanionMovePasses(): void {
		$map = new FqcnMap([[
			'path' => 'src/A/C.php', 'fqcn' => 'Acme\\A\\C', 'target' => 'Acme\\B\\C', 'owner' => 'x', 'ticket' => 'RE-X',
			'companions' => ['Acme\\A\\D'],
		]], 'Acme\\', 'src/');
		$before = ['src/A/C.php' => '<?php namespace Acme\\A; class C {} class D { function f() { return new C; } }'];
		$after = ['src/B/C.php' => str_replace('namespace Acme\\A;', 'namespace Acme\\B;', $before['src/A/C.php'])];
		$this->assertSame([], (new MoveEquivalence($before, $after, $map))->check());
	}

	/** Cases 22 and 23 preserve the insufficient-depth refusal and exact depth compensation. */
	public function testDirectoryDepthControls(): void {
		$map = new FqcnMap([['path' => 'src/A/C.php', 'fqcn' => 'Acme\\A\\C', 'target' => 'Acme\\Deep\\A\\C', 'owner' => 'x', 'ticket' => 'RE-X']], 'Acme\\', 'src/');
		$before = ['src/A/C.php' => '<?php namespace Acme\\A; class C { function f() { return dirname(__DIR__, 2); } }'];
		foreach ([2 => false, 3 => true] as $depth => $passes) {
			$after = ['src/Deep/A/C.php' => "<?php namespace Acme\\Deep\\A; class C { function f() { return dirname(__DIR__, $depth); } }"];
			$this->assertSame($passes, (new MoveEquivalence($before, $after, $map))->check() === []);
		}
	}
}
