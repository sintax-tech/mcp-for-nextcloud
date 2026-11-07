<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\LibScanner;
use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\SourceParser;
use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Tables\TablesApiUsage;

require __DIR__ . '/../../../../vendor/autoload.php';

/** Builds an additional pin without rewriting the minimum-version read and row contracts. */
final class TablesPinGenerator {
	/** @var array<string, string> Official release archive checksums. */
	private const PINS = ['2.3.2' => '29953270010587fc8c0a95cfaba7b167adafb93d2f1a0a5a89e42f2d2be1cf28',
		'2.4.0' => '47847b64ee65a58afd2a8ac3f943c53128a927a1b321ed94c4a322daa6b5eb99'];

	/**
	 * @param string $archives Directory containing tables-v<version>.tar.gz for both release pins.
	 * @param string $serverFiles SHA-pinned server-35.0.0-Entity.php and QBMapper.php.
	 * @return void Generate complete consumed surfaces, file identities and real native factory sources.
	 */
	public static function run(string $archives, string $serverFiles): void {
		$manifest = json_decode(file_get_contents(__DIR__ . '/schema-api.json'), true, 512, JSON_THROW_ON_ERROR);
		$server = json_decode(file_get_contents(__DIR__ . '/tables-sources.json'), true, 512, JSON_THROW_ON_ERROR)['35']['server'];
		$tree = sys_get_temp_dir() . '/tables-pin-' . bin2hex(random_bytes(8));
		mkdir($tree, 0700, true);
		try {
			$sources = [];
			foreach (self::PINS as $pin => $sha) {
				$file = $archives . '/tables-v' . $pin . '.tar.gz';
				if (hash_file('sha256', $file) !== $sha) throw new RuntimeException('Release archive hash mismatch: ' . $pin);
				(new PharData($file))->extractTo($tree . '/' . $pin);
				$sources[$pin] = $tree . '/' . $pin . '/tables/lib/';
			}
			mkdir($tree . '/apps');
			rename($tree . '/2.4.0/tables', $tree . '/apps/tables');
			$sources['2.4.0'] = $tree . '/apps/tables/lib/';
			foreach ($server['files'] as $path => $sha) {
				$file = $serverFiles . '/server-35.0.0-' . basename($path);
				if (hash_file('sha256', $file) !== $sha) throw new RuntimeException('Server source hash mismatch');
				if (!is_dir(dirname($tree . '/' . $path))) mkdir(dirname($tree . '/' . $path), 0700, true);
				copy($file, $tree . '/' . $path);
			}
			$classes = array_values(array_unique(array_filter([...array_keys((new LibScanner(dirname(__DIR__, 4) . '/lib'))->classes()),
				...TablesApiUsage::EXTRA_CLASSES, 'OCA\\Tables\\Model\\ViewSettings', 'OCA\\Tables\\Constants\\ViewLayout'],
				static fn (string $class): bool => str_starts_with($class, 'OCA\\Tables\\'))));
			foreach (glob($sources['2.4.0'] . 'Service/ColumnTypes/*.php') as $file) $classes[] = 'OCA\\Tables\\Service\\ColumnTypes\\' . basename($file, '.php');
			$classes = array_values(array_unique($classes));
			sort($classes);
			$parser = new SourceParser($tree);
			$result = ['version' => '2.4.0', 'url' => 'https://github.com/nextcloud-releases/tables/releases/download/v2.4.0/tables-v2.4.0.tar.gz',
				'archiveSha256' => self::PINS['2.4.0'], 'server' => $server, 'surfaces' => [], 'files' => [], 'identicalRowSources' => []];
			foreach ($classes as $class) {
				$result['surfaces'][$class] = $parser->surface($class) ?? throw new RuntimeException('Missing native class: ' . $class);
				$file = str_replace('\\', '/', substr($class, strlen('OCA\\Tables\\'))) . '.php';
				$result['files'][$file] = hash_file('sha256', $sources['2.4.0'] . $file);
			}
			$rowFiles = ['Service/RowService.php', 'Db/Row2Mapper.php', 'Db/RowCellNumberMapper.php', 'Db/RowCellSelectionMapper.php', 'Helper/ColumnsHelper.php'];
			foreach (glob($sources['2.4.0'] . 'Service/ColumnTypes/*.php') as $file) $rowFiles[] = substr($file, strlen($sources['2.4.0']));
			foreach ($rowFiles as $file) {
				$old = hash_file('sha256', $sources['2.3.2'] . $file);
				$new = hash_file('sha256', $sources['2.4.0'] . $file);
				if ($old !== $new) throw new RuntimeException('Row semantics require a new extraction: ' . $file);
				$result['identicalRowSources'][$file] = $new;
			}
			$write = json_decode(file_get_contents(__DIR__ . '/tables-write.json'), true, 512, JSON_THROW_ON_ERROR);
			$result['rowWrite'] = ['referenceVersion' => '2.3.2', 'referenceArchiveSha256' => self::PINS['2.3.2'], 'facts' => $write['versions']['2.3.2']];
			foreach ($manifest['sources']['2.4.0']['files'] as $file => $sha) {
				if (hash_file('sha256', $sources['2.4.0'] . $file) !== $sha) throw new RuntimeException('Tag and release source mismatch: ' . $file);
			}
			$manifest['sources']['2.4.0']['url'] = $result['url'];
			$manifest['sources']['2.4.0']['archiveSha256'] = $result['archiveSha256'];
			$factories = [...array_keys($manifest['factorySources']['2.3.2']), 'Constants/ViewLayout.php', 'Model/ViewSettings.php'];
			foreach ($factories as $file) {
				$source = file_get_contents($sources['2.4.0'] . $file);
				$manifest['factorySources']['2.4.0'][$file] = $source;
				$manifest['sources']['2.4.0']['files'][$file] = hash('sha256', $source);
			}
			self::write('tables-2.4.0.json', $result);
			self::write('schema-api.json', $manifest);
		} finally {
			foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tree, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
				$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
			}
			rmdir($tree);
		}
	}

	/**
	 * @param string $file Fixture basename.
	 * @param array<string, mixed> $data Verified native facts.
	 * @return void Persist deterministic JSON.
	 */
	private static function write(string $file, array $data): void {
		file_put_contents(__DIR__ . '/' . $file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
	}
}

TablesPinGenerator::run($argv[1], $argv[2]);
