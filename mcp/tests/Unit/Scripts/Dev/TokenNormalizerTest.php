<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts\Dev;

use OCA\Mcp\Scripts\Dev\SourceTreeIndex;
use OCA\Mcp\Scripts\Dev\TokenNormalizer;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../scripts/dev/bootstrap.php';

/**
 * Covers the name resolution of the token comparator: which names become fully qualified class references and
 * which stay literal.
 */
final class TokenNormalizerTest extends TestCase {
	/** Imports, aliases, group imports, fully qualified names and `::class` all resolve to the class. */
	public function testEveryFormOfClassReferenceResolves(): void {
		$code = <<<'PHP'
			<?php
			namespace App\Tools;
			use App\Service\Guard;
			use App\Service\Policy as Rules;
			use App\Model\{Card, Board as Table};
			final class Writer extends Base implements \App\Contract {
				public function run(?Guard $g, Rules|Card $r): Table {
					return new \App\Other(Guard::class, Local::make());
				}
			}
			PHP;
		$classes = $this->classes($code, ['App\Tools\Writer', 'App\Tools\Base', 'App\Tools\Local']);
		$this->assertSame(['App\Tools\Writer', 'App\Tools\Base', 'App\Contract', 'App\Service\Guard', 'App\Service\Policy', 'App\Model\Card', 'App\Model\Board', 'App\Other', 'App\Service\Guard', 'App\Tools\Local'], $classes);
	}

	/** Functions, constants, builtin types, members and named arguments are never class references. */
	public function testNonClassNamesStayLiteral(): void {
		$code = <<<'PHP'
			<?php
			namespace App;
			final class A {
				const LIMIT = 1;
				public function run(int $x, string $name): ?array {
					return strlen($name) > PHP_EOL ? $this->Guard($x) : self::LIMIT + static::Guard(name: true, Guard: null);
				}
			}
			PHP;
		$this->assertSame(['App\A'], $this->classes($code, ['App\A', 'App\Guard']));
		$stream = $this->stream($code, ['App\A', 'App\Guard']);
		$this->assertContains('T:strlen', $stream);
		$this->assertContains('T:PHP_EOL', $stream);
		$this->assertContains('T:int', $stream);
		$this->assertContains('T:Guard', $stream);
	}

	/** An unqualified name the namespace does not declare stays literal, so a missing import is visible. */
	public function testAnUndeclaredUnqualifiedNameStaysLiteral(): void {
		$stream = $this->stream("<?php\nnamespace App;\nnew Missing();\n", []);
		$this->assertContains('T:Missing', $stream);
	}

	/** A trait used inside a class is a class reference; a closure's `use` is not an import. */
	public function testTraitUseAndClosureUseAreNotImports(): void {
		$code = "<?php\nnamespace App;\nuse Lib\\Helper;\nfinal class A {\n\tuse Helper;\n\tpublic function f(): \\Closure {\n\t\t\$x = 1;\n\t\treturn function () use (\$x) { return \$x; };\n\t}\n}\n";
		$stream = $this->stream($code, ['App\A']);
		$this->assertContains('C:Lib\Helper', $stream);
		$this->assertContains('T:use', $stream);
		$this->assertSame(2, count(array_keys($stream, 'T:use', true)));
	}

	/** Renames apply to resolved names and to strings holding a class name, keeping a leading backslash. */
	public function testRenamesApplyToNamesAndClassStrings(): void {
		$code = "<?php\nnamespace App;\nuse Old\\Guard;\n\$a = [Guard::class, 'Old\\\\Guard', \"\\\\Old\\\\Guard\", 'Old\\\\Guard::x', 'plain'];\n";
		$index = new SourceTreeIndex([]);
		$stream = array_column((new TokenNormalizer($index))->normalize($code, ['Old\Guard' => 'New\Guard'])->stream, 0);
		$this->assertContains('C:New\Guard', $stream);
		$this->assertContains('S:New\Guard', $stream);
		$this->assertContains('S:\New\Guard', $stream);
		$this->assertContains('S:Old\Guard::x', $stream);
		$this->assertContains('S:plain', $stream);
	}

	/** Whitespace and comments are left out. */
	public function testWhitespaceAndCommentsAreLeftOut(): void {
		$a = $this->stream("<?php\n// one\n\$x = 1;\n", []);
		$b = $this->stream("<?php\n/** two */\n\$x   =\n1;\n", []);
		$this->assertSame($a, $b);
	}

	/**
	 * Class references of a source, in order.
	 *
	 * @param string $code PHP source
	 * @param list<string> $declared classes the tree declares
	 * @return list<string> resolved names
	 */
	private function classes(string $code, array $declared): array {
		$classes = [];
		foreach ($this->stream($code, $declared) as $token) {
			if (str_starts_with($token, 'C:')) {
				$classes[] = substr($token, 2);
			}
		}
		return $classes;
	}

	/**
	 * Normalized stream of a source.
	 *
	 * @param string $code PHP source
	 * @param list<string> $declared classes the tree declares
	 * @return list<string> tokens without lines
	 */
	private function stream(string $code, array $declared): array {
		$files = [];
		foreach ($declared as $n => $class) {
			$position = (int)strrpos($class, '\\');
			$files["f$n.php"] = "<?php\nnamespace " . substr($class, 0, $position) . ";\nclass " . substr($class, $position + 1) . " {}\n";
		}
		return array_column((new TokenNormalizer(new SourceTreeIndex($files)))->normalize($code)->stream, 0);
	}
}
