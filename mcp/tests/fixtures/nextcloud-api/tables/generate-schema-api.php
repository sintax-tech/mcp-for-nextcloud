<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Re-extracts consumed source contracts from the SHA-pinned official tag archives, never MCP doubles. */
final class TablesSchemaSourceExtractor {
	/**
	 * @param string $directory Directory containing v<version>.tar.gz from the manifest URLs.
	 * @return void
	 */
	public static function run(string $directory): void {
		$path = __DIR__ . '/schema-api.json';
		$data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		foreach ($data['sources'] as $version => &$entry) {
			$archive = $directory . '/v' . $version . '.tar.gz';
			if (!is_file($archive) || hash_file('sha256', $archive) !== $entry['archiveSha256']) throw new RuntimeException('Archive SHA256 mismatch: ' . $version);
			$source = self::sources($archive);
			foreach ($entry['files'] as $file => $expected) {
				if (!isset($source[$file]) || hash('sha256', $source[$file]) !== $expected) throw new RuntimeException('Source SHA256 mismatch: ' . $version . '/' . $file);
			}
			foreach ($data['contracts'][$version] ?? [] as $file => $methods) {
				$found = isset($source[$file]) ? self::methods($source[$file]) : [];
				foreach ($methods as $method => $_) $data['contracts'][$version][$file][$method] = $found[$method] ?? null;
			}
			foreach ($data['factorySources'][$version] ?? [] as $file => $_) {
				if (!isset($source[$file])) throw new RuntimeException('Missing factory source: ' . $file);
				$data['factorySources'][$version][$file] = $source[$file];
				$entry['files'][$file] = hash('sha256', $source[$file]);
			}
		}
		unset($entry);
		file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
	}

	/**
	 * @param string $archive Verified gzip tar.
	 * @return array<string,string> Native lib files by relative name.
	 */
	private static function sources(string $archive): array {
		$files = [];
		foreach (new RecursiveIteratorIterator(new PharData($archive)) as $file) {
			if (!$file->isFile()) continue;
			$name = $file->getPathname(); $start = strpos($name, '/lib/');
			if ($start === false || !str_ends_with($name, '.php')) continue;
			$files[substr($name, $start + 5)] = $file->getContent();
		}
		return $files;
	}

	/**
	 * @param string $source Official PHP bytes.
	 * @return array<string,array{signature:string,sha256:string}> Significant-token method contracts.
	 */
	private static function methods(string $source): array {
		$tokens = token_get_all($source); $result = [];
		for ($i = 0; $i < count($tokens); $i++) {
			if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
			$j = $i + 1;
			while (isset($tokens[$j]) && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $j++;
			if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) continue;
			$name = $tokens[$j][1]; $text = ''; $depth = 0; $started = false;
			for ($k = $j + 1; $k < count($tokens); $k++) {
				$token = $tokens[$k];
				if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) continue;
				$text .= is_array($token) ? $token[1] : $token;
				if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { $depth++; $started = true; }
				elseif ($token === '}' && --$depth === 0 && $started) break;
				if (!$started && $token === ';') break;
			}
			$brace = strpos($text, '{');
			$result[$name] = ['signature' => substr($text, 0, $brace === false ? strlen($text) : $brace), 'sha256' => hash('sha256', $text)];
		}
		return $result;
	}
}

TablesSchemaSourceExtractor::run($argv[1] ?? throw new InvalidArgumentException('Pass the pinned archive directory.'));
