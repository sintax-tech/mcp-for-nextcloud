<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Generates exact-pin signature/behavior contracts from SHA-checked official archives and merges the floor into aggregate
 * fixtures. Source is parsed, never executed. Run only in a pinned PHP container: php generate-forms.php <pins> <archives>.
 */
use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\SourceParser;
use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\FixtureFile;
use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Forms\FormsSourcePins;

require dirname(__DIR__, 4) . '/vendor/autoload.php';
[, $pins, $archives] = $argv;
$classes = ['OCA\\Forms\\Db\\Form', 'OCA\\Forms\\Db\\Question', 'OCA\\Forms\\Db\\Option', 'OCA\\Forms\\Db\\Share', 'OCA\\Forms\\Db\\FormMapper', 'OCA\\Forms\\Service\\FormsService', 'OCA\\Forms\\Constants', 'OCA\\Forms\\Exception\\NoSuchFormException', ...\OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Forms\FormsResultsApiUsage::CLASSES, ...\OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Forms\FormsCreationApiUsage::CLASSES];
$floor = null;
foreach (FormsSourcePins::all() as $version => $sha) {
	$archive = "$pins/forms-v$version.tar.gz";
	if (hash_file('sha256', $archive) !== $sha) { throw new RuntimeException('Forms archive SHA mismatch'); }
	$tree = sys_get_temp_dir() . '/mcp-forms-contract-' . bin2hex(random_bytes(6));
	mkdir($tree . '/apps', 0777, true);
	try {
		(new PharData($archive))->extractTo($tree . '/apps');
		mkdir($tree . '/lib/public/AppFramework/Db', 0777, true);
		$nativePins = json_decode(file_get_contents(dirname(__DIR__) . '/tables/tables-sources.json'), true)['32']['server']['files'];
		foreach ($nativePins as $path => $expected) {
			$source = "$archives/server-32.0.0-" . basename($path);
			if (hash_file('sha256', $source) !== $expected) { throw new RuntimeException('Server signature SHA mismatch'); }
			copy($source, "$tree/$path");
		}
		$parser = new SourceParser($tree);
		$data = ['version' => $version, 'sha256' => $sha, 'classes' => [], 'sources' => []];
		foreach ($classes as $class) { $data['classes'][$class] = $parser->surface($class); }
		foreach (['Service/FormsService', 'Db/Form', 'Db/Question', 'Db/Option', 'Db/Share', 'Db/FormMapper', 'Db/ShareMapper', 'Constants', 'Controller/PageController', ...\OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Forms\FormsResultsApiUsage::SOURCES, ...\OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Forms\FormsCreationApiUsage::SOURCES] as $file) {
			$source = "$tree/apps/forms/lib/$file.php";
			$data['sources'][$file] = ['sha256' => hash_file('sha256', $source), 'source' => file_get_contents($source)];
		}
		file_put_contents(__DIR__ . "/$version.json", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
		$floor ??= $data['classes'];
	} finally {
		$walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tree, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($walk as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
		rmdir($tree);
	}
}
foreach (range(31, 35) as $major) {
	$file = dirname(__DIR__) . "/$major.php";
	$fixture = require $file;
	foreach ($floor as $class => $surface) { $fixture['classes'][$class] = $surface; }
	ksort($fixture['classes']);
	$fixture['formsSources'] = ['forms' => ['version' => '5.3.0', 'sha256' => FormsSourcePins::all()['5.3.0']]];
	$before = file_get_contents($file);
	FixtureFile::write($file, substr($before, 0, strpos($before, 'return [')), $fixture);
}
echo "Generated " . count(FormsSourcePins::all()) . " verified Forms contracts and aggregate signature surfaces\n";
