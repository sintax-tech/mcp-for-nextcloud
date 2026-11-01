<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Architecture\Inventory;

use OCA\Mcp\Scripts\Dev\SourceTreeIndex;
use RuntimeException;

require_once __DIR__ . '/../../../scripts/dev/bootstrap.php';

/** Portable inventory of app source, including logical owners while legacy files await their move. */
final class AppTree {
	/** @var array<string,string> Source paths relative to the app. */
	public readonly array $files;
	/** @var array<string,array<string,mixed>> Every historical/current/future class name and its map row. */
	public readonly array $names;
	/** @var array<string,string> Present class name to physical path. */
	public readonly array $classes;

	/** @param string $root App directory, never an artifact directory. */
	public function __construct(public readonly string $root) {
		$map = self::json($root . '/scripts/dev/fqcn-map.json');
		$names = [];
		foreach ($map['entries'] as $entry) {
			foreach ([$entry['fqcn'], ...($entry['via'] ?? []), $entry['target'], ...($entry['companions'] ?? [])] as $name) {
				if (str_starts_with($name, 'OCA\\Mcp\\')) { $names[$name] = $entry; }
			}
		}
		$this->names = $names;
		$this->files = self::sources($root);
		$classes = self::declarations($this->files, $names);
		if (count($classes) < 13) { throw new RuntimeException('Empty/incomplete app class inventory'); }
		$this->classes = $classes;
		$completed = self::json(__DIR__ . '/../completed-tickets.json');
		foreach ($map['entries'] as $entry) {
			$chain = [$entry['fqcn'], ...($entry['via'] ?? []), $entry['target']];
			if (array_intersect($chain, array_keys($classes)) === [] && !($entry['target'] === 'removed' && in_array($entry['ticket'], $completed, true))) { throw new RuntimeException('Missing mapped app file: ' . $entry['fqcn']); }
		}
	}

	/** @param array<string,string> $files Sources. @param array<string,array<string,mixed>> $names Map index. @return array<string,string> Verified class-to-file index. */
	public static function declarations(array $files, array $names): array {
		$classes = [];
		foreach ($files as $path => $source) {
			if (!str_starts_with($path, 'lib/') || !str_ends_with($path, '.php')) { continue; }
			foreach (SourceTreeIndex::declarations($source) as $class) {
				if (isset($classes[$class])) { throw new RuntimeException('Duplicate class: ' . $class); }
				if (!isset($names[$class])) { throw new RuntimeException('Unmapped class: ' . $class); }
				if ($path !== self::path($class) && !in_array($class, $names[$class]['companions'] ?? [], true)) { throw new RuntimeException('Namespace/path mismatch: ' . $path . ' -> ' . $class); }
				$classes[$class] = $path;
			}
		}
		return $classes;
	}

	/** @param string $class FQCN. @return string Logical layer or module, using physical namespaces once moved. */
	public function owner(string $class): string {
		if (str_starts_with($class, 'OCA\\Mcp\\OAuth\\Storage\\')) { return 'Storage'; }
		foreach (['Platform', 'Access', 'Mcp', 'OAuth'] as $layer) {
			if (str_starts_with($class, 'OCA\\Mcp\\' . $layer . '\\')) { return $layer; }
		}
		if (preg_match('/^OCA\\\\Mcp\\\\(Modules\\\\[^\\\\]+)/', $class, $m)) { return $m[1]; }
		return $this->names[$class]['owner'] ?? 'unmapped';
	}

	/** @param array<string,string> $files Isolated source replacements for checker controls. @return self Same identity/map index with altered source bytes only. */
	public function withFiles(array $files): self {
		$copy = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
		$copy->root = $this->root; $copy->names = $this->names; $copy->classes = $this->classes; $copy->files = $files;
		return $copy;
	}

	/** @param string $class Historical or present class. @return string Present physical path. */
	public function present(string $class): string {
		$entry = $this->names[$class] ?? throw new RuntimeException('Unmapped identity: ' . $class);
		foreach ([$entry['target'], ...array_reverse($entry['via'] ?? []), $entry['fqcn']] as $candidate) {
			if (isset($this->classes[$candidate])) { return $this->classes[$candidate]; }
		}
		throw new RuntimeException('Missing mapped identity: ' . $class);
	}

	/** @param string $class App FQCN. @return string PSR-4 path. */
	public static function path(string $class): string { return 'lib/' . str_replace('\\', '/', substr($class, 8)) . '.php'; }

	/** @param string $path JSON file. @return array<mixed> Required parsed document. */
	public static function json(string $path): array {
		if (!is_file($path)) { throw new RuntimeException('Missing manifest: ' . $path); }
		$data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($data)) { throw new RuntimeException('Expected JSON array/object: ' . $path); }
		return $data;
	}

	/** @param string $root App root. @return array<string,string> Only app code; never tests/scripts/vendor. */
	public static function sources(string $root): array {
		$files = [];
		foreach (['lib', 'src', 'templates', 'appinfo'] as $directory) {
			if (!is_dir($root . '/' . $directory)) { throw new RuntimeException('Missing app root: ' . $directory); }
			$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
			foreach ($iterator as $file) {
				if ($file->isLink()) { throw new RuntimeException('Symlink in app source: ' . $file); }
				if ($file->isFile() && in_array($file->getExtension(), ['php', 'js'], true)) {
					$files[substr($file->getPathname(), strlen($root) + 1)] = (string)file_get_contents($file->getPathname());
				}
			}
		}
		ksort($files);
		return $files;
	}
}
