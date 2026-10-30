<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Fills tests/fixtures/nextcloud-api/mail-draft-bcc.json: for each official Mail release pinned there (URL and SHA256),
 * whether MailTransmission::saveDraft() stores the Bcc header of a draft.
 * - `addHeaders+getRaw`: saveDraft() calls Horde_Mime_Mail::addHeaders() and stores getRaw(); the bundled
 *   Horde_Mime_Mail::addHeader() keeps Bcc apart (`$this->_bcc`) and getRaw() serializes only the other headers, so the
 *   draft has no Bcc;
 * - `buildMimeHeaders+toString`: saveDraft() builds the headers with buildMimeHeaders(), which adds Bcc, and stores the
 *   message part with those headers, so the draft keeps Bcc.
 * Any other shape stops the generator. MailDraftBccContractTest checks MailDraftBcc::MINIMUM_VERSION against it. The
 * archives are checked against their SHA256 first and only read as text, never executed or unpacked to disk.
 *
 * Usage: php tests/fixtures/nextcloud-api/generate-mail-draft-bcc.php <archive directory>
 *
 * <archive directory> holds mail-<version>.tar.gz of every release of the file.
 */

[, $archives] = $argv + [null, null];
if (!is_dir((string)$archives)) {
	fwrite(STDERR, "usage: php generate-mail-draft-bcc.php <archive directory>\n");
	exit(2);
}
$file = __DIR__ . '/mail-draft-bcc.json';
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
	$transmission = (string)$phar['mail/lib/Service/MailTransmission.php']->getContent();
	if (preg_match('/public function saveDraft\(.*?return \[\$account, \$draftsMailbox, \$newUid\];/s', $transmission, $save) !== 1) {
		throw new RuntimeException('saveDraft() not found in ' . $archive);
	}
	$horde = (string)$phar['mail/vendor/bytestream/horde-mime/lib/Horde/Mime/Mail.php']->getContent();
	$bccApart = preg_match("/if \\(\\\$lc_header === 'bcc'\\) \\{\\s*\\\$this->_bcc = \\\$value;/", $horde) === 1;
	if (str_contains($save[0], '$mail->addHeaders($headers);') && str_contains($save[0], '$mail->getRaw(false)') && $bccApart) {
		$path = 'addHeaders+getRaw';
		$kept = false;
	} elseif (str_contains($save[0], '$this->buildMimeHeaders(') && str_contains($save[0], '$message->getBcc()')
		&& str_contains($save[0], "'headers' => \$draftHeaders")
		&& preg_match("/function buildMimeHeaders\\(.*?new Horde_Mime_Headers_Addresses\\('Bcc'/s", $transmission) === 1) {
		$path = 'buildMimeHeaders+toString';
		$kept = true;
	} else {
		throw new RuntimeException('Unknown saveDraft() header handling in ' . $archive);
	}
	$releases[$version]['saveDraft'] = $path;
	$releases[$version]['bccKept'] = $kept;
}
file_put_contents($file, json_encode($releases, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo 'Read saveDraft() of Mail ' . implode(', ', array_keys($releases)) . "\n";
