<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts\Dev;

use OCA\Mcp\Scripts\Dev\ClassRewrites;
use OCA\Mcp\Scripts\Dev\FqcnMap;
use OCA\Mcp\Scripts\Dev\PathRewrites;
use OCA\Mcp\Scripts\Dev\SourceTreeIndex;
use OCA\Mcp\Scripts\Dev\SourceTrees;

/** Real-tree fixtures that enumerate every filename reader; preparation exists only in the in-memory baseline. */
final class RepositoryMoveFixture {
	/** @var array<string,string>|null original tree */
	private static ?array $tree = null;

	/**
	 * Moves a mapped class with all full/relative path readers updated.
	 *
	 * @param string $relative source path below lib/
	 * @param bool $prepared whether earlier, explicitly required reader preparations are modeled
	 * @return array{array<string,string>,array<string,string>,FqcnMap,string,string} before, after, map, old/new paths
	 */
	public static function move(string $relative, bool $prepared = true): array {
		$before = self::$tree ??= SourceTrees::fromDirectory(__DIR__ . '/../../../..', ['appinfo', 'lib', 'scripts', 'templates', 'tests', 'js']);
		$data = json_decode($before['scripts/dev/fqcn-map.json'], true);
		$old = 'lib/' . $relative;
		$evasion = str_contains($relative, 'Evasion' . 'Probe');
		if ($evasion) {
			$class = implode('\\', ['OCA', 'Mcp', 'Tools', 'Mail', 'EvasionProbe']);
			$data['entries'][] = ['path' => $old, 'fqcn' => $class, 'target' => str_replace('Tools', 'Modules', $class), 'owner' => 'Mail', 'ticket' => 'RE-25'];
			$before[$old] = '<?php namespace OCA\\Mcp\\Tools\\Mail; class EvasionProbe {}';
			$before['scripts/dev/fqcn-map.json'] = json_encode($data);
		}
		$old = CurrentMoveSource::path($before, $data, $old);
		if (!$prepared) {
			$before = CurrentMoveSource::unprepared($before, $old);
		}
		$before['scripts/dev/fqcn-map.json'] = json_encode($data, JSON_THROW_ON_ERROR);
		$entry = array_values(array_filter($data['entries'], static fn (array $e): bool => $e['path'] === $old))[0];
		$oldClass = $entry['fqcn'];
		$newClass = $entry['target'];
		$map = FqcnMap::fromJson($before['scripts/dev/fqcn-map.json']);
		$new = $map->pathOf($newClass);
		$namespace = substr($oldClass, 0, (int)strrpos($oldClass, '\\'));
		$newNamespace = substr($newClass, 0, (int)strrpos($newClass, '\\'));
		$imports = [];
		foreach ($before as $path => &$content) {
			if (str_starts_with($path, 'tests/') && str_ends_with($path, '.php') && $prepared) {
				$content = MoveReaderPreparation::code($content, $path);
				token_get_all($content, TOKEN_PARSE);
				if ($evasion && str_contains($content, "\$folder = \$base . '/Tools/Mail';")) {
					$content = str_replace("\$folder = \$base . '/Tools/Mail';\n\t\tmkdir(\$folder, 0700, true);\n\t\t\$file = \$folder . '/EvasionProbe.php';", "\$file = \$base . '/" . $relative . "';\n\t\t\$folder = dirname(\$file);\n\t\tmkdir(\$folder, 0700, true);", $content);
				}
			}
			if (str_starts_with($path, dirname($old) . '/') && str_ends_with($path, '.php')) {
				foreach (SourceTreeIndex::declarations($content) as $class) {
					if (str_starts_with($class, $namespace . '\\') && $class !== $oldClass) {
						$tail = substr($class, strlen($namespace) + 1);
						$class = str_contains($tail, '\\') ? $namespace . '\\' . explode('\\', $tail)[0] : $class;
						$imports[] = $class;
					}
				}
			}
		}
		unset($content);
		$after = [];
		foreach ($before as $path => $content) {
			if (!str_ends_with($path, '.php')) {
				// A move commit updates the paths and the class names a non-PHP test holds (the DI golden names modules).
				$after[$path] = str_starts_with($path, 'tests/') ? PathRewrites::text(ClassRewrites::text($content, [$oldClass => $newClass]), [$old => $new]) : $content;
				continue;
			}
			$group = 'use ' . $namespace . '\\{';
			while (($start = strpos($content, $group)) !== false) {
				$end = strpos($content, '};', $start);
				$items = explode(',', substr($content, $start + strlen($group), $end - $start - strlen($group)));
				$uses = implode(' ', array_map(static fn (string $item): string => 'use ' . $namespace . '\\' . trim($item) . ';', $items));
				$content = substr_replace($content, $uses, $start, $end + 2 - $start);
			}
			$content = str_replace([$oldClass, str_replace('\\', '\\\\', $oldClass)], [$newClass, str_replace('\\', '\\\\', $newClass)], $content);
			$tokens = token_get_all($content);
			$changed = '';
			foreach ($tokens as $token) {
				if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
					// Exact literal replacement through the same pair vocabulary; readers are enumerated separately.
					$raw = $token[1];
					$value = \OCA\Mcp\Scripts\Dev\PhpStringLiteral::value($raw);
					$mapped = PathRewrites::value($value, [$old => $new]);
					$changed .= $mapped === null ? $raw : "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $mapped) . "'";
				} else {
					if (is_array($token) && $token[0] === T_NAME_QUALIFIED && $token[1] === basename(str_replace('\\', '/', $namespace)) . '\\' . basename($old, '.php')
						&& str_contains($content, 'use ' . $namespace . ';')) {
						$changed .= '\\' . $newClass;
					} else {
						$changed .= is_array($token) ? $token[1] : $token;
					}
				}
			}
			if (str_contains($changed, "namespace $namespace;")) {
				if ($path === $old) {
					$lines = implode('', array_map(static fn (string $class): string => "use $class;\n", array_unique($imports)));
					$changed = str_replace("namespace $namespace;", "namespace $newNamespace;\n$lines", $changed);
				} elseif (!str_contains($changed, "use $newClass;")) {
					$changed = str_replace("namespace $namespace;", "namespace $namespace;\nuse $newClass;", $changed);
				}
			}
			token_get_all($changed, TOKEN_PARSE);
			$after[$path === $old ? $new : $path] = $changed;
		}
		return [$before, $after, $map, $old, $new];
	}
	/**
	 * Changes only path literals, keeping FQCN/import updates and preparation intact in a forgotten-reader probe.
	 *
	 * @param string $code prepared PHP reader
	 * @param array<string,string> $pairs path changes, optionally reversed
	 * @return string rewritten PHP
	 */
	public static function pathsOnly(string $code, array $pairs): string {
		$out = '';
		foreach (token_get_all($code, TOKEN_PARSE) as $token) {
			if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
				$mapped = PathRewrites::value(\OCA\Mcp\Scripts\Dev\PhpStringLiteral::value($token[1]), $pairs);
				$out .= $mapped === null ? $token[1] : "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $mapped) . "'";
			} else {
				$out .= is_array($token) ? $token[1] : $token;
			}
		}
		return $out;
	}

	/**
	 * Finds path readers in the current tree, including newly added oracle manifests.
	 *
	 * @param array<string,string> $tree source tree before the simulated move
	 * @param string $old moved app-relative path
	 * @return list<string> reader paths; script fixtures are not application readers
	 */
	public static function readers(array $tree, string $old): array {
		return array_keys(array_filter($tree, static fn (string $code, string $path): bool =>
			(str_starts_with($path, 'tests/') || str_starts_with($path, 'js/'))
			&& !str_contains($path, '/Scripts/') && str_contains($code, basename($old)), ARRAY_FILTER_USE_BOTH));
	}

	/**
	 * Retains exactly one reader's retired paths while keeping all other move edits intact.
	 *
	 * @param array<string,string> $before baseline tree
	 * @param array<string,string> $after correctly updated tree
	 * @param string $old old app-relative path
	 * @param string $new destination app-relative path
	 * @return iterable<string,array<string,string>> reader path => tree with that reader forgotten
	 */
	public static function forgottenReaders(array $before, array $after, string $old, string $new): iterable {
		foreach (self::readers($before, $old) as $reader) {
			$forgotten = $after;
			$forgotten[$reader] = str_ends_with($reader, '.php')
				? self::pathsOnly($after[$reader], [$new => $old])
				: PathRewrites::text($after[$reader], [$new => $old]);
			yield $reader => $forgotten;
		}
	}

}
