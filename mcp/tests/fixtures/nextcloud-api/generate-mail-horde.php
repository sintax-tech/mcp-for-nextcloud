<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Writes tests/fixtures/nextcloud-api/mail-horde-surfaces.json: the public surface of the Horde IMAP and MIME classes
 * the Mail message tools call (NextcloudApiUsage::HORDE), restricted to the members they use, as the official Mail
 * release pinned for each major in mail-sources.json ships them in its vendor tree. These classes are not Nextcloud's
 * and not the Mail app's own, so generate-mail.php does not carry them; this narrow surface lets
 * MailHordeSurfaceContractTest check every method, parameter, constant and documented magic property the app uses,
 * per major. The archives are checked against their SHA256 first and only read as text, never executed.
 *
 * Usage: php tests/fixtures/nextcloud-api/generate-mail-horde.php <archive directory>
 *
 * <archive directory> holds mail-<version>.tar.gz of every pin, as for generate-mail.php.
 */

use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\NextcloudApiUsage;

require __DIR__ . '/../../../vendor/autoload.php';

[, $archives] = $argv + [null, null];
if (!is_dir((string)$archives)) {
	fwrite(STDERR, "usage: php generate-mail-horde.php <verified archive directory>\n");
	exit(2);
}
$pins = json_decode((string)file_get_contents(__DIR__ . '/mail-sources.json'), true, 512, JSON_THROW_ON_ERROR);

/**
 * The file of a PEAR-named Horde class inside the vendor tree of the Mail app.
 *
 * @param string $root unpacked mail/ directory
 * @param string $class class such as Horde_Imap_Client_Fetch_Query
 * @return string|null the file, or null when the vendor tree has none
 */
$fileOf = static function (string $root, string $class): ?string {
	$package = str_starts_with($class, 'Horde_Mime') ? 'horde-mime' : 'horde-imap-client';
	$file = $root . '/vendor/bytestream/' . $package . '/lib/' . str_replace('_', '/', $class) . '.php';
	return is_file($file) ? $file : null;
};

/**
 * The surface one file declares for a class: its parent, public methods with parameter names, constants and the
 * magic properties its docblock documents.
 *
 * @param string $file PHP file
 * @param string $class class declared in it
 * @return array{parent:string|null, methods:array<string, list<string>>, constants:list<string>, properties:list<string>}
 */
$parse = static function (string $file, string $class): array {
	$source = (string)file_get_contents($file);
	$out = ['parent' => null, 'methods' => [], 'constants' => [], 'properties' => []];
	if (preg_match('~/\*\*((?:(?!\*/).)*)\*/\s*(?:abstract\s+|final\s+)*class\s+' . preg_quote($class, '~') . '\b([^{]*)~s', $source, $m) === 1) {
		preg_match_all('/@property(?:-read)?\s+\S+\s+\$(\w+)/', $m[1], $props);
		$out['properties'] = $props[1];
		if (preg_match('/extends\s+(\w+)/', $m[2], $parent) === 1) {
			$out['parent'] = $parent[1];
		}
	} elseif (preg_match('~class\s+' . preg_quote($class, '~') . '\b([^{]*)~', $source, $m) === 1 && preg_match('/extends\s+(\w+)/', $m[1], $parent) === 1) {
		$out['parent'] = $parent[1];
	}
	preg_match_all('/^\s*(?:public\s+)?const\s+(\w+)\s*=/m', $source, $constants);
	$out['constants'] = $constants[1];
	preg_match_all('/^\s*(?:final\s+|static\s+)*public\s+(?:static\s+)?function\s+(\w+)\s*\(([^)]*)\)/m', $source, $methods, PREG_SET_ORDER);
	foreach ($methods as [, $name, $parameters]) {
		preg_match_all('/(\.\.\.)?\$(\w+)(\s*=)?/', $parameters, $params, PREG_SET_ORDER);
		$out['methods'][$name] = array_map(static fn (array $p): string => ($p[1] ?? '') . $p[2] . (isset($p[3]) && $p[3] !== '' ? '?' : ''), $params);
	}
	return $out;
};

$surfaces = [];
foreach ($pins as $major => $pin) {
	$archive = $archives . '/mail-' . $pin['mail']['version'] . '.tar.gz';
	if (!is_file($archive) || hash_file('sha256', $archive) !== $pin['mail']['sha256']) {
		throw new RuntimeException('Source SHA256 mismatch: ' . $archive);
	}
	$tree = sys_get_temp_dir() . '/mcp-mail-horde-' . bin2hex(random_bytes(6));
	mkdir($tree);
	try {
		(new PharData($archive))->extractTo($tree, null, true);
		$root = $tree . '/mail';
		$installed = json_decode((string)file_get_contents($root . '/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
		$packages = [];
		foreach ($installed['packages'] ?? $installed as $package) {
			if (in_array($package['name'], ['bytestream/horde-imap-client', 'bytestream/horde-mime'], true)) {
				$packages[$package['name']] = $package['version'];
			}
		}
		ksort($packages);
		$classes = [];
		foreach (array_keys(NextcloudApiUsage::HORDE) as $class) {
			$surface = ['methods' => [], 'constants' => [], 'properties' => []];
			for ($current = $class; $current !== null;) {
				$file = $fileOf($root, $current);
				if ($file === null) {
					throw new RuntimeException("Mail {$pin['mail']['version']} has no $current");
				}
				$own = $parse($file, $current);
				$surface['methods'] += $own['methods'];
				$surface['constants'] = array_values(array_unique([...$surface['constants'], ...$own['constants']]));
				$surface['properties'] = array_values(array_unique([...$surface['properties'], ...$own['properties']]));
				$current = $own['parent'];
			}
			// Only the members the app uses are kept; one the release lacks is simply absent, which the test reports.
			$used = NextcloudApiUsage::HORDE[$class];
			$classes[$class] = [
				'methods' => array_intersect_key($surface['methods'], $used['methods'] ?? []),
				'constants' => array_values(array_intersect($used['constants'] ?? [], $surface['constants'])),
				'properties' => array_values(array_intersect($used['properties'] ?? [], $surface['properties'])),
			];
		}
		// The sync token format the Mail app stores per folder: getSyncToken() joins key.value pairs with commas in base64,
		// and the key of the UIDVALIDITY is the one Horde_Imap_Client_Data_Sync::$map gives to 'uidvalidity'.
		$base = (string)file_get_contents((string)$fileOf($root, 'Horde_Imap_Client_Base'));
		$sync = (string)file_get_contents((string)$fileOf($root, 'Horde_Imap_Client_Data_Sync'));
		preg_match("/'(\\w)'\\s*=>\\s*'uidvalidity'/", $sync, $key);
		$tokenFormat = [
			'uidvalidityKey' => $key[1] ?? null,
			'joinedByComma' => preg_match("/public function getSyncToken\\(\\\$mailbox\\)\\s*\\{[^}]*\\\$out\\[\\] = \\\$key \\. \\\$val;[^}]*\\}\\s*return base64_encode\\(implode\\(',', \\\$out\\)\\);/s", $base) === 1,
			'syncChecksUidvalidity' => str_contains($sync, "\$curr['V'] != \$this->uidvalidity") && str_contains($sync, 'UIDVALIDITY_CHANGED'),
		];
		$surfaces[$major] = ['mail' => $pin['mail']['version'], 'packages' => $packages, 'classes' => $classes, 'syncToken' => $tokenFormat];
	} finally {
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tree, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($files as $file) {
			$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		}
		rmdir($tree);
	}
}
file_put_contents(__DIR__ . '/mail-horde-surfaces.json', json_encode($surfaces, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo 'Generated Horde surfaces for ' . implode(', ', array_map(static fn (string $m, array $s): string => "NC$m (Mail {$s['mail']})", array_keys($surfaces), $surfaces)) . "\n";
