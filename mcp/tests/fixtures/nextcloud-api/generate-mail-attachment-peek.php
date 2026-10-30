<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Fills tests/fixtures/nextcloud-api/mail-attachment-peek.json: for each official Mail release pinned there (URL and
 * SHA256), whether OCA\Mail\IMAP\MessageMapper::getAttachment() fetches the attachment part and its MIME header with
 * `'peek' => true`, so that reading an attachment sets no \Seen flag. MailAttachmentPeekContractTest checks that the
 * first release that does is the minimum the attachment tool requires (MailAttachmentSupport::MINIMUM_VERSION) and the
 * release before it does not. The archives are checked against their SHA256 first and only read as text, never
 * executed or unpacked to disk.
 *
 * Usage: php tests/fixtures/nextcloud-api/generate-mail-attachment-peek.php <archive directory>
 *
 * <archive directory> holds mail-<version>.tar.gz of every release of the file.
 */

[, $archives] = $argv + [null, null];
if (!is_dir((string)$archives)) {
	fwrite(STDERR, "usage: php generate-mail-attachment-peek.php <archive directory>\n");
	exit(2);
}
$file = __DIR__ . '/mail-attachment-peek.json';
$releases = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

/**
 * Whether a call of the method on the query, with the attachment id, passes `'peek' => true`.
 *
 * @param string $body source of getAttachment()
 * @param string $method bodyPart or mimeHeader
 * @return bool true when every such call peeks, false when one does not or there is none
 */
$peeks = static function (string $body, string $method): bool {
	$calls = preg_match_all('/->' . $method . '\(\$attachmentId(?<args>[^;]*)\);/', $body, $matches);
	if ($calls === false || $calls === 0) {
		return false;
	}
	foreach ($matches['args'] as $arguments) {
		if (preg_match("/'peek'\\s*=>\\s*true/", $arguments) !== 1) {
			return false;
		}
	}
	return true;
};

foreach ($releases as $version => $release) {
	$archive = $archives . '/mail-' . $version . '.tar.gz';
	if (!is_file($archive) || hash_file('sha256', $archive) !== $release['sha256']) {
		throw new RuntimeException('Source SHA256 mismatch: ' . $archive);
	}
	$phar = new PharData($archive);
	$info = (string)$phar['mail/appinfo/info.xml']->getContent();
	if ((string)simplexml_load_string($info)->version !== $version) {
		throw new RuntimeException('Source app version mismatch in ' . $archive);
	}
	$source = (string)$phar['mail/lib/IMAP/MessageMapper.php']->getContent();
	if (preg_match('/public function getAttachment\((?<body>.*?)\n\t}\n/s', $source, $method) !== 1) {
		throw new RuntimeException('MessageMapper::getAttachment() not found in ' . $archive);
	}
	$releases[$version]['bodyPartPeek'] = $peeks($method['body'], 'bodyPart');
	$releases[$version]['mimeHeaderPeek'] = $peeks($method['body'], 'mimeHeader');
}
file_put_contents($file, json_encode($releases, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo 'Checked getAttachment() of Mail ' . implode(', ', array_keys($releases)) . "\n";
