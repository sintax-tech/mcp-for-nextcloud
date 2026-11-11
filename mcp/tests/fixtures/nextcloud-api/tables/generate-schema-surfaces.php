<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\{SourceParser, LibScanner};

require __DIR__ . '/../../../../vendor/autoload.php';

/** Regenerates optional schema surfaces through the same official-source parser as generate.php. */
final class TablesSchemaSurfaceGenerator {
	/**
	 * @param string $archives SHA-pinned official Tables tag archives.
	 * @param string $serverFiles Entity/QBMapper files verified by tables-sources.json.
	 * @return void
	 */
	public static function run(string $archives, string $serverFiles): void {
		$manifest = json_decode(file_get_contents(__DIR__ . '/schema-api.json'), true, 512, JSON_THROW_ON_ERROR);
		$serverPins = json_decode(file_get_contents(__DIR__ . '/tables-sources.json'), true, 512, JSON_THROW_ON_ERROR);
		$baseline = require dirname(__DIR__) . '/35.php';
		$classes = array_values(array_filter(array_keys((new LibScanner(dirname(__DIR__, 4) . '/lib'))->classes()),
			static fn (string $class): bool => str_starts_with($class, 'OCA\\Tables\\') && !array_key_exists($class, $baseline['classes'])));
		sort($classes);
		$result = ['classes' => $classes, 'pins' => []];
		foreach (['1.0.10' => '34', '2.2.2' => '35', '2.3.1' => '35', '2.3.2' => '35'] as $pin => $major) {
			$archive = $archives . '/v' . $pin . '.tar.gz';
			$expected = $manifest['sources'][$pin]['archiveSha256'];
			if (hash_file('sha256', $archive) !== $expected) throw new RuntimeException('Archive SHA mismatch');
			$tree = sys_get_temp_dir() . '/tables-schema-surfaces-' . bin2hex(random_bytes(8));
			mkdir($tree . '/apps/tables/lib', 0700, true);
			try {
				foreach (new RecursiveIteratorIterator(new PharData($archive)) as $file) {
					$path = $file->getPathname();
					$start = strpos($path, '/lib/');
					if (!$file->isFile() || $start === false || !str_ends_with($path, '.php')) continue;
					$dest = $tree . '/apps/tables/lib/' . substr($path, $start + 5);
					if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0700, true);
					file_put_contents($dest, $file->getContent());
				}
				foreach ($serverPins[$major]['server']['files'] as $path => $hash) {
					$source = $serverFiles . '/server-' . $major . '.0.0-' . basename($path);
					if (hash_file('sha256', $source) !== $hash) throw new RuntimeException('Server source SHA mismatch');
					if (!is_dir(dirname($tree . '/' . $path))) mkdir(dirname($tree . '/' . $path), 0700, true);
					copy($source, $tree . '/' . $path);
				}
				$parser = new SourceParser($tree);
				$surfaces = [];
				foreach ($classes as $class) $surfaces[$class] = $parser->surface($class) ?? throw new RuntimeException('Pin lacks ' . $class);
				$result['pins'][$pin] = ['archiveSha256' => $expected, 'server' => $serverPins[$major]['server'], 'surfaces' => $surfaces];
			} finally {
				foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tree, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
					$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
				}
				rmdir($tree);
			}
		}
		file_put_contents(__DIR__ . '/schema-api-surfaces.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
	}
}

TablesSchemaSurfaceGenerator::run($argv[1], $argv[2]);
