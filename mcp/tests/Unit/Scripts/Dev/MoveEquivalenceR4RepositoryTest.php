<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts\Dev;

use OCA\Mcp\Scripts\Dev\FqcnMap;
use OCA\Mcp\Scripts\Dev\MoveEquivalence;
use OCA\Mcp\Scripts\Dev\SourceTreeIndex;
use OCA\Mcp\Scripts\Dev\SourceTrees;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../scripts/dev/bootstrap.php';

/** Exercises the five real RE-05 moves from R4; fixture names/paths are assembled to avoid stale test data. */
final class MoveEquivalenceR4RepositoryTest extends TestCase {
	/** @var array<string,string>|null original app tree, shared between provider cases */
	private static ?array $tree = null;

	/**
	 * Real Service classes can move with exact class/path references updated, including non-PHP test readers.
	 *
	 * @param string $short class basename
	 */
	#[DataProvider('classes')]
	public function testRealPlannedMovePasses(string $short): void {
		[$before, $after, $map, $old] = $this->move($short);
		$equivalence = new MoveEquivalence($before, $after, $map);
		$this->assertSame([], $equivalence->check());
		$this->assertArrayHasKey($old, $equivalence->pairs());
	}

	/**
	 * @return iterable<string,array{string}> the five real moves requested by R4
	 */
	public static function classes(): iterable {
		foreach (['GrantPolicy', 'OverridePreview', 'AccessInspector', 'GuestRestrictions', 'GrantMatrix'] as $class) {
			yield $class => [$class];
		}
	}

	/**
	 * The PHP reader of GrantPolicy/OverridePreview cannot retain the retired path.
	 *
	 * @param string $short real class read by a PHP test
	 */
	#[DataProvider('readClasses')]
	public function testForgottenRealPhpReaderFails(string $short): void {
		[$before, $after, $map, $old, $new] = $this->move($short);
		$readers = [];
		foreach ($before as $path => $content) {
			if (str_starts_with($path, 'tests/') && str_ends_with($path, '.php') && str_contains($content, $old)) {
				$after[$path] = str_replace($new, $old, $after[$path]);
				$readers[] = $path;
			}
		}
		$this->assertNotSame([], $readers);
		$this->assertStringContainsString("still refers to the file $old", implode("\n", (new MoveEquivalence($before, $after, $map))->check()));
	}

	/**
	 * @return iterable<string,array{string}> the two previously blocked moves
	 */
	public static function readClasses(): iterable {
		yield 'GrantPolicy' => ['GrantPolicy'];
		yield 'OverridePreview' => ['OverridePreview'];
	}

	/** A forgotten .cjs reader fails even when the PHP reader is correctly updated. */
	public function testForgottenRealFrontendReaderFails(): void {
		[$before, $after, $map, $old, $new] = $this->move('GrantPolicy');
		$reader = 'tests/Frontend/admin-levels.test.cjs';
		$this->assertStringContainsString($new, $after[$reader]);
		$after[$reader] = $before[$reader];
		$problems = implode("\n", (new MoveEquivalence($before, $after, $map))->check());
		$this->assertStringContainsString("$reader:", $problems);
		$this->assertStringContainsString("still refers to the file $old", $problems);
	}

	/**
	 * Applies one mapped move in memory, importing old namespace siblings without changing behavior.
	 * Unused imports are harmless and keep the fixture independent of each class's implementation details.
	 *
	 * @param string $short Service class basename
	 * @return array{array<string,string>,array<string,string>,FqcnMap,string,string} old tree, new tree, map, old/new paths
	 */
	private function move(string $short): array {
		$before = self::$tree ??= SourceTrees::fromDirectory(__DIR__ . '/../../../..', ['appinfo', 'lib', 'scripts', 'templates', 'tests']);
		$map = FqcnMap::fromJson($before['scripts/dev/fqcn-map.json']);
		$namespace = implode('\\', ['OCA', 'Mcp', 'Service']);
		$oldClass = $namespace . '\\' . $short;
		$entry = array_values(array_filter($map->entries, static fn (array $e): bool => $e['fqcn'] === $oldClass))[0];
		$old = $entry['path'];
		$newClass = $entry['target'];
		$new = $map->pathOf($newClass);
		if (!isset($before[$old])) {
			$this->markTestSkipped("$short already moved; its ticket proves the real move");
		}
		$newNamespace = substr($newClass, 0, (int)strrpos($newClass, '\\'));
		$imports = [];
		foreach ($before as $path => $content) {
			if (str_starts_with($path, 'lib/Service/') && str_ends_with($path, '.php')) {
				foreach (SourceTreeIndex::declarations($content) as $class) {
					if (substr($class, 0, (int)strrpos($class, '\\')) === $namespace && $class !== $oldClass) {
						$imports[] = $class;
					}
				}
			}
		}
		$after = [];
		foreach ($before as $path => $content) {
			if (!str_ends_with($path, '.php') && !str_starts_with($path, 'tests/')) {
				$after[$path] = $content;
				continue;
			}
			$changed = str_replace([$oldClass, str_replace('\\', '\\\\', $oldClass), $old], [$newClass, str_replace('\\', '\\\\', $newClass), $new], $content);
			if (str_ends_with($path, '.php') && str_contains($changed, "namespace $namespace;")) {
				if ($path === $old) {
					$lines = implode('', array_map(static fn (string $class): string => "use $class;\n", $imports));
					$changed = str_replace("namespace $namespace;", "namespace $newNamespace;\n$lines", $changed);
				} elseif (!str_contains($changed, "use $newClass;")) {
					$changed = str_replace("namespace $namespace;", "namespace $namespace;\nuse $newClass;", $changed);
				}
			}
			$after[$path === $old ? $new : $path] = $changed;
		}
		return [$before, $after, $map, $old, $new];
	}
}
