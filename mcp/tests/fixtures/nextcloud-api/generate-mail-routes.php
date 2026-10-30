<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Fills tests/fixtures/nextcloud-api/mail-routes.json: for each official Mail release pinned there (URL and SHA256),
 * the URL and verb of the Mail routes the Mail links use, as appinfo/routes.php of the release declares them:
 * `page#mailbox` and `page#thread` (the web pages MailLinks points to) and `messages#getThread` (the API the Mail front
 * end loads a linked conversation from). MailRoutesContractTest checks them. The archives are checked against their
 * SHA256 first and only read as text, never executed or unpacked to disk.
 *
 * Usage: php tests/fixtures/nextcloud-api/generate-mail-routes.php <archive directory>
 *
 * <archive directory> holds mail-<version>.tar.gz of every release of the file.
 */

[, $archives] = $argv + [null, null];
if (!is_dir((string)$archives)) {
	fwrite(STDERR, "usage: php generate-mail-routes.php <archive directory>\n");
	exit(2);
}
$file = __DIR__ . '/mail-routes.json';
$releases = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
$names = ['page#mailbox', 'page#thread', 'messages#getThread'];

foreach ($releases as $version => $release) {
	$archive = $archives . '/mail-' . $version . '.tar.gz';
	if (!is_file($archive) || hash_file('sha256', $archive) !== $release['sha256']) {
		throw new RuntimeException('Source SHA256 mismatch: ' . $archive);
	}
	$phar = new PharData($archive);
	if ((string)simplexml_load_string((string)$phar['mail/appinfo/info.xml']->getContent())->version !== $version) {
		throw new RuntimeException('Source app version mismatch in ' . $archive);
	}
	$source = (string)$phar['mail/appinfo/routes.php']->getContent();
	$routes = [];
	foreach ($names as $name) {
		$pattern = "/'name'\\s*=>\\s*'" . preg_quote($name, '/') . "',\\s*'url'\\s*=>\\s*'([^']*)',\\s*'verb'\\s*=>\\s*'([^']*)'/";
		$routes[$name] = preg_match($pattern, $source, $match) === 1 ? ['url' => $match[1], 'verb' => $match[2]] : null;
	}
	$releases[$version]['routes'] = $routes;
}
file_put_contents($file, json_encode($releases, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo 'Read the Mail routes of ' . implode(', ', array_keys($releases)) . "\n";
