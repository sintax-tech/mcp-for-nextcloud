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
		$this->assertContains('F:strlen', $stream);
		$this->assertContains('K:PHP_EOL', $stream);
		$this->assertContains('K:int', $stream);
		$this->assertContains('T:Guard', $stream);
	}

	/** An unqualified name the namespace does not declare stays literal, so a missing import is visible. */
	public function testAnUndeclaredUnqualifiedNameStaysLiteral(): void {
		$stream = $this->stream("<?php\nnamespace App;\nnew Missing();\n", []);
		$this->assertContains('K:Missing', $stream);
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
		$this->assertContains('S=New\Guard', $stream);
		$this->assertContains('S=\New\Guard', $stream);
		$this->assertContains('S=New\\Guard::x', $stream);
		$this->assertContains("S:'plain'", $stream);
	}

	/** Text inside strings, interpolations, heredocs and nowdocs is compared byte for byte (review A2). */
	public function testStringContentKeepsItsWhitespace(): void {
		$pairs = [
			["<?php\necho \"hello \$x\";\n", "<?php\necho \" hello \$x\";\n"],
			["<?php\necho \"\$x done\";\n", "<?php\necho \"\$x  done\";\n"],
			["<?php\necho <<<EOT\nhello \$x\nEOT;\n", "<?php\necho <<<EOT\n hello \$x\nEOT;\n"],
			["<?php\necho <<<'EOT'\nhello\nEOT;\n", "<?php\necho <<<'EOT'\nhello \nEOT;\n"],
			["<?php\necho \"\\q\";\n", "<?php\necho \"q\";\n"],
			["<?php\necho 'a';\n", "<?php\necho 'a ';\n"],
		];
		foreach ($pairs as [$before, $after]) {
			$this->assertNotSame($this->stream($before, []), $this->stream($after, []), $after);
		}
	}

	/** An attribute's name is a class even with arguments, in a group, qualified or aliased (review A3). */
	public function testAttributeNamesResolveAsClassesWithArguments(): void {
		$code = "<?php\nnamespace App;\nuse Lib\\Attr\\Guard;\nuse Lib\\Attr\\Other as Alias;\nfinal class A {\n\t#[Guard(action: 'x'), Alias, \\Lib\\Attr\\Full(Guard::class)]\n\tpublic function f(): void {}\n}\n";
		$this->assertSame(['App\A', 'Lib\Attr\Guard', 'Lib\Attr\Other', 'Lib\Attr\Full', 'Lib\Attr\Guard'], $this->classes($code, ['App\A']));
		$redirected = str_replace('use Lib\\Attr\\Guard;', 'use Lib\\Attr\\NoCheck as Guard;', $code);
		$this->assertContains('C:Lib\Attr\NoCheck', $this->stream($redirected, ['App\A']));
	}

	/** A constant imported with `use const` resolves to its import, so redirecting it changes the stream. */
	public function testImportedConstantsResolveToTheirImport(): void {
		$code = "<?php\nnamespace App;\nuse const Lib\\FLAG;\nuse const Lib\\{LIMIT as MAX};\necho FLAG + MAX + PHP_EOL;\n";
		$stream = $this->stream($code, []);
		$this->assertContains('K:Lib\FLAG', $stream);
		$this->assertContains('K:Lib\LIMIT', $stream);
		$this->assertContains('K:PHP_EOL', $stream);
		$this->assertNotSame($stream, $this->stream(str_replace('use const Lib\\FLAG;', 'use const Other\\FLAG;', $code), []));
	}

	/** Imports and declarations of functions and constants that the reviewer found unresolved (review R2). */
	public function testFunctionAndConstantNamesAreResolved(): void {
		$pairs = [
			'mixed group import' => ["<?php\nnamespace X;\nuse A\\{function f};\nf();\n", "<?php\nnamespace X;\nuse A\\{function g as f};\nf();\n"],
			'constant and class with one alias' => ["<?php\nnamespace X;\nuse A\\FLAG;\nuse const B\\FLAG;\necho FLAG;\n", "<?php\nnamespace X;\nuse A\\FLAG;\nuse const C\\FLAG;\necho FLAG;\n"],
			'namespace alias in a call' => ["<?php\nnamespace X;\nuse A\\Ns;\nNs\\f();\n", "<?php\nnamespace X;\nuse B\\Ns;\nNs\\f();\n"],
			'free function namespace' => ["<?php\nnamespace A;\nfunction f() {}\n", "<?php\nnamespace B;\nfunction f() {}\n"],
			'namespace constant' => ["<?php\nnamespace A;\nconst LIMIT = 1;\n", "<?php\nnamespace B;\nconst LIMIT = 1;\n"],
		];
		foreach ($pairs as $case => [$before, $after]) {
			$this->assertNotSame($this->stream($before, []), $this->stream($after, []), $case);
		}
		$this->assertContains('F:A\f', $this->stream("<?php\nnamespace X;\nuse A\\{function f, const C};\nf(C);\n", []));
		$this->assertContains('K:A\C', $this->stream("<?php\nnamespace X;\nuse A\\{function f, const C};\nf(C);\n", []));
	}

	/** A call to a function the namespace declares in the tree resolves to it, so moving the caller alone fails. */
	public function testCallsToNamespacedFunctionsResolve(): void {
		$index = new SourceTreeIndex(['f.php' => "<?php\nnamespace A;\nfunction helper() {}\nconst LIMIT = 2;\nclass K { const LIMIT = 1; public function helper() {} }\n"]);
		$stream = array_column((new TokenNormalizer($index))->normalize("<?php\nnamespace A;\necho helper() + LIMIT + strlen('x');\n")->stream, 0);
		$this->assertContains('F:A\helper', $stream);
		$this->assertContains('K:A\LIMIT', $stream);
		$this->assertContains('F:strlen', $stream);
		$this->assertTrue($index->declaresFunction('a\HELPER'));
		$this->assertFalse($index->declaresFunction('A\K\helper'));
	}

	/** `__DIR__`/`__FILE__` expressions fold to the path they yield for the file's own location. */
	public function testDirectoryExpressionsFoldToPaths(): void {
		$code = "<?php\n\$a = [__DIR__, __FILE__, dirname(__DIR__, 2) . '/lib/X.php', dirname(__FILE__), __DIR__ . '/../y'];\n";
		$normalizer = new TokenNormalizer(new SourceTreeIndex([]));
		$paths = array_values(array_filter(array_column($normalizer->normalize($code, [], 'tests/Unit/ATest.php')->stream, 0), static fn (string $t): bool => str_starts_with($t, 'P:')));
		$this->assertSame(['P:tests/Unit|__DIR__', 'P:tests/Unit/ATest.php|__FILE__', 'P:.|dirname(__DIR__)', 'P:tests/Unit|dirname(__FILE__)', 'P:tests/Unit|__DIR__'], $paths);
		$this->assertContains('T:__DIR__', array_column($normalizer->normalize($code)->stream, 0));
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
