<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Fixtures\Polls;

use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\FixtureFile;
use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\LibScanner;
use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\SourceParser;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use FilesystemIterator;
require __DIR__ . '/../../../../vendor/autoload.php';

/** Regenerates only Polls surfaces, preserving other apps and recording absent 8.6 APIs as null. */
final class PollsMutationApiFixture {
	/**
	 * @param string $archives Directory of archives/core files named by polls-sources.json.
	 * @return void
	 * @throws RuntimeException If a source pin or archive layout does not match.
	 */
	public static function run(string $archives): void {
		$pins = json_decode(file_get_contents(__DIR__ . '/polls-sources.json'), true, 512, JSON_THROW_ON_ERROR);
		$classes = (new LibScanner(dirname(__DIR__, 4) . '/lib'))->classes();
		foreach ($pins as $major => $pin) {
			$archive = $archives . '/v' . $pin['polls']['version'] . '.tar.gz';
			self::verify($archive, $pin['polls']['sha256']);
			$tree = sys_get_temp_dir() . '/polls-api-' . bin2hex(random_bytes(8));
			mkdir($tree . '/unpack', recursive: true);
			try {
				(new PharData($archive))->extractTo($tree . '/unpack');
				$roots = glob($tree . '/unpack/*', GLOB_ONLYDIR);
				if (count($roots) !== 1) {
					throw new RuntimeException('Expected one native app root');
				}
				mkdir($tree . '/apps');
				rename($roots[0], $tree . '/apps/polls');
				foreach ($pin['server']['files'] as $path => $sha) {
					$source = $archives . '/server-' . $pin['server']['version'] . '-' . basename($path);
					self::verify($source, $sha);
					if (!is_dir($tree . '/' . dirname($path))) {
						mkdir($tree . '/' . dirname($path), recursive: true);
					}
					copy($source, $tree . '/' . $path);
				}
				$parser = new SourceParser($tree);
				$file = dirname(__DIR__) . '/' . $major . '.php';
				$fixture = require $file;
				foreach (array_keys($classes) as $class) {
					if (str_starts_with($class, 'OCA\Polls\\')) {
						$fixture['classes'][$class] = $parser->surface($class);
					}
				}
				ksort($fixture['classes']);
				$old = file_get_contents($file);
				FixtureFile::write($file, substr($old, 0, strpos($old, 'return [')), $fixture);
			} finally {
				$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tree,
					FilesystemIterator::SKIP_DOTS),
					RecursiveIteratorIterator::CHILD_FIRST);
				foreach ($files as $file) {
					if ($file->isDir()) {
						rmdir($file->getPathname());
					} else {
						unlink($file->getPathname());
					}
				}
				rmdir($tree);
			}
		}
	}

	/**
	 * @param string $path Exact source file.
	 * @param string $sha Expected archive/core SHA256.
	 * @return void
	 * @throws RuntimeException If bytes differ from the official pin.
	 */
	private static function verify(string $path, string $sha): void {
		if (!is_file($path) || hash_file('sha256', $path) !== $sha) {
			throw new RuntimeException('Native source pin mismatch: ' . $path);
		}
	}
}
if (isset($argv[1]) && realpath($argv[0]) === __FILE__) {
	PollsMutationApiFixture::run($argv[1]);
}
