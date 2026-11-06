<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

use RuntimeException;

/**
 * Reads the files of the app as `path => content`, paths relative to the app folder (`lib/...`, `tests/...`):
 * from a Git revision, without touching the working tree, or from a folder on disk.
 */
final class SourceTrees {
	/**
	 * Reads every file under the app folder at a revision, in one `git cat-file --batch` process.
	 *
	 * @param string $repository any folder inside the Git work tree
	 * @param string $revision commit-ish
	 * @param string $appFolder app folder relative to the repository root, e.g. `mcp`
	 * @return array<string,string> path relative to the app folder => content
	 * @throws RuntimeException when Git fails or the revision does not exist
	 */
	public static function fromGit(string $repository, string $revision, string $appFolder): array {
		$prefix = trim($appFolder, '/') . '/';
		$listing = self::run(['git', '-C', $repository, 'ls-tree', '-r', '-z', '--full-tree', $revision, '--', $prefix], '');
		$blobs = [];
		foreach (array_filter(explode("\0", $listing)) as $line) {
			[$meta, $path] = explode("\t", $line, 2);
			[$mode, $type, $object] = explode(' ', $meta);
			if ($type === 'blob' && !in_array($mode, ['100644', '100755'], true)) {
				throw new RuntimeException("Unsupported Git file mode $mode: $path");
			}
			if ($type === 'blob') {
				$blobs[substr($path, strlen($prefix))] = $object;
			}
		}
		$output = self::run(['git', '-C', $repository, 'cat-file', '--batch'], implode("\n", $blobs) . "\n");
		$files = [];
		$offset = 0;
		foreach ($blobs as $path => $object) {
			$end = strpos($output, "\n", $offset);
			$header = explode(' ', substr($output, $offset, $end - $offset));
			if ($header[0] !== $object || !isset($header[2])) {
				throw new RuntimeException("git cat-file returned an unexpected header for $path");
			}
			$files[$path] = substr($output, $end + 1, (int)$header[2]);
			$offset = $end + 1 + (int)$header[2] + 1;
		}
		ksort($files, SORT_STRING);
		return $files;
	}

	/**
	 * Reads every file under a folder on disk.
	 *
	 * @param string $folder the app folder
	 * @param list<string> $only top-level subfolders to read (e.g. `lib`, `tests`); empty for all
	 * @return array<string,string> path relative to the folder => content
	 */
	public static function fromDirectory(string $folder, array $only = []): array {
		$folder = rtrim($folder, '/');
		$files = [];
		$roots = $only === [] ? [$folder] : array_map(static fn (string $sub): string => "$folder/$sub", $only);
		foreach ($roots as $root) {
			if (!is_dir($root)) {
				continue;
			}
			$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
			foreach ($iterator as $file) {
				if ($file->isFile()) {
					$files[substr($file->getPathname(), strlen($folder) + 1)] = (string)file_get_contents($file->getPathname());
				}
			}
		}
		ksort($files, SORT_STRING);
		return $files;
	}

	/**
	 * Runs a command without a shell and returns its standard output.
	 *
	 * @param list<string> $command program and arguments
	 * @param string $input standard input
	 * @return string standard output
	 * @throws RuntimeException when the command exits with a non-zero status
	 */
	private static function run(array $command, string $input): string {
		$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		if (!is_resource($process)) {
			throw new RuntimeException('cannot start ' . $command[0]);
		}
		// stdin and stdout are pumped together: git cat-file writes while it reads, so writing all the input first
		// would block on a full stdout pipe.
		stream_set_blocking($pipes[0], false);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$output = '';
		$error = '';
		$open = [1 => $pipes[1], 2 => $pipes[2]];
		while ($open !== []) {
			$read = array_values($open);
			$write = $input !== '' ? [$pipes[0]] : [];
			$except = null;
			if ($input === '' && is_resource($pipes[0])) {
				fclose($pipes[0]);
			}
			stream_select($read, $write, $except, 5);
			if ($write !== []) {
				$input = (string)substr($input, (int)fwrite($pipes[0], substr($input, 0, 65536)));
			}
			foreach ($open as $key => $pipe) {
				$chunk = (string)fread($pipe, 65536);
				$key === 1 ? $output .= $chunk : $error .= $chunk;
				if (feof($pipe)) {
					fclose($pipe);
					unset($open[$key]);
				}
			}
		}
		if (is_resource($pipes[0])) {
			fclose($pipes[0]);
		}
		if (proc_close($process) !== 0) {
			throw new RuntimeException(implode(' ', $command) . " failed: $error");
		}
		return $output;
	}
}
