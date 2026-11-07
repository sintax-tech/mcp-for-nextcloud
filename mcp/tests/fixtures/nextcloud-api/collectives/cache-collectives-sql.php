<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Extracts only the SQL proof sources from locally downloaded official server tarballs.
 * Usage: php cache-collectives-sql.php <tarball directory> <generator archives directory>
 * Download URLs and whole-archive/file SHA256s are fixed in collectives-sql-sources.json; no network is used here.
 */
if ($argc !== 3) {
	throw new InvalidArgumentException('Expected server tarball directory and generator archives directory');
}
$pins = json_decode(file_get_contents(__DIR__ . '/collectives-sql-sources.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($pins as $major => $pin) {
	$archive = $argv[1] . '/' . basename($pin['url']);
	if (!is_file($archive) || hash_file('sha256', $archive) !== $pin['sha256']) {
		throw new RuntimeException('SQL server archive SHA mismatch: ' . $archive);
	}
	// Stream extraction avoids indexing the entire multi-gigabyte server archive in PHP memory.
	$tree = sys_get_temp_dir() . '/collectives-sql-cache-' . bin2hex(random_bytes(5));
	mkdir($tree);
	try {
		$paths = array_map(static fn (string $file): string => 'nextcloud/' . $file, array_keys($pin['files']));
		$process = proc_open(['tar', '-xf', $archive, '-C', $tree, ...$paths], [STDIN, STDOUT, STDERR], $pipes);
		if (!is_resource($process) || proc_close($process) !== 0) {
			throw new RuntimeException('SQL source extraction failed: ' . $archive);
		}
		foreach ($pin['files'] as $file => $sha) {
			$bytes = file_get_contents($tree . '/nextcloud/' . $file);
			if (hash('sha256', $bytes) !== $sha) {
				throw new RuntimeException('SQL bundled source SHA mismatch: ' . $file);
			}
			file_put_contents($argv[2] . '/sql-' . $major . '-' . hash('sha256', $file) . '.php', $bytes);
		}
	} finally {
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tree, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($files as $file) {
			$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		}
		rmdir($tree);
	}
	echo 'NC' . $major . ' SQL sources verified and cached' . PHP_EOL;
}
