<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Fills tests/fixtures/nextcloud-api/mail-flag-event.json: for each official Mail release pinned there (URL and SHA256),
 * the constructor parameters of OCA\Mail\Events\MessageFlaggedEvent (type and name, in order) and the third argument
 * MailManager::flagMessage() builds the event with. MailFlagEventContractTest checks that MailFlagEvent builds the event
 * the same way: the UID up to Mail 5.10.x, the cached message from 5.11.0 on. The archives are checked against their
 * SHA256 first and only read as text, never executed or unpacked to disk.
 *
 * Usage: php tests/fixtures/nextcloud-api/generate-mail-flag-event.php <archive directory>
 *
 * <archive directory> holds mail-<version>.tar.gz of every release of the file.
 */

[, $archives] = $argv + [null, null];
if (!is_dir((string)$archives)) {
	fwrite(STDERR, "usage: php generate-mail-flag-event.php <archive directory>\n");
	exit(2);
}
$file = __DIR__ . '/mail-flag-event.json';
$releases = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

foreach ($releases as $version => $release) {
	$archive = $archives . '/mail-' . $version . '.tar.gz';
	if (!is_file($archive) || hash_file('sha256', $archive) !== $release['sha256']) {
		throw new RuntimeException('Source SHA256 mismatch: ' . $archive);
	}
	$phar = new PharData($archive);
	if ((string)simplexml_load_string((string)$phar['mail/appinfo/info.xml']->getContent())->version !== $version) {
		throw new RuntimeException('Source app version mismatch in ' . $archive);
	}
	$event = (string)$phar['mail/lib/Events/MessageFlaggedEvent.php']->getContent();
	if (preg_match('/function __construct\((?<params>[^)]*)\)/s', $event, $match) !== 1) {
		throw new RuntimeException('MessageFlaggedEvent::__construct() not found in ' . $archive);
	}
	$parameters = [];
	foreach (explode(',', $match['params']) as $parameter) {
		if (preg_match('/(?:(?:private|public|protected|readonly)\s+)*(\??[\w\\\\]+)\s+\$(\w+)/', $parameter, $one) === 1) {
			$parameters[] = $one[1] . ' $' . $one[2];
		}
	}
	$manager = (string)$phar['mail/lib/Service/MailManager.php']->getContent();
	if (preg_match('/new MessageFlaggedEvent\(\s*\$account,\s*\$mb,\s*\$(\w+),/', $manager, $built) !== 1) {
		throw new RuntimeException('flagMessage() event not found in ' . $archive);
	}
	$releases[$version]['constructor'] = $parameters;
	$releases[$version]['flagMessageThirdArgument'] = '$' . $built[1];
}
file_put_contents($file, json_encode($releases, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo 'Read MessageFlaggedEvent of Mail ' . implode(', ', array_keys($releases)) . "\n";
