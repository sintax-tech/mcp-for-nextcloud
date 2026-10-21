<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

// Parse source with the current PHP interpreter; never load the inspected files.
$app = dirname(__DIR__);
$paths = array_slice($argv, 1) ?: ['lib', 'appinfo', 'templates', 'scripts', 'tests'];
$excluded = ['vendor', 'build', '.cache', '.phpunit.cache'];
$files = [];
try {
    foreach ($paths as $input) {
        $path = str_starts_with($input, DIRECTORY_SEPARATOR) ? $input : $app . '/' . $input;
        if (!file_exists($path) || is_link($path)) {
            throw new RuntimeException("Missing or symbolic-link path: $input");
        }
        if (is_file($path)) {
            if (pathinfo($path, PATHINFO_EXTENSION) === 'php') {
                $files[$path] = true;
            }
            continue;
        }
        $directory = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator($directory, static function (SplFileInfo $file) use ($excluded): bool {
            return !$file->isLink() && (!$file->isDir() || !in_array($file->getFilename(), $excluded, true));
        });
        foreach (new RecursiveIteratorIterator($filter) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[$file->getPathname()] = true;
            }
        }
    }
    if ($files === []) {
        throw new RuntimeException('No PHP files found in: ' . implode(', ', $paths));
    }
    ksort($files, SORT_STRING);
    $failed = false;
    foreach (array_keys($files) as $file) {
        $process = proc_open([PHP_BINARY, '-l', $file], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException("Cannot start PHP syntax check: $file");
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0) {
            fwrite(STDERR, "Syntax check failed: $file\n$output");
            $failed = true;
        }
    }
    if ($failed) {
        exit(1);
    }
    fwrite(STDOUT, 'Checked ' . count($files) . " PHP files.\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
