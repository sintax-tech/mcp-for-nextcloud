<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Fills tests/fixtures/nextcloud-api/mail-send-path.json: for each official Mail release pinned there (URL and SHA256),
 * what the send tool relies on in the Mail app's send path:
 * - `chain`: the handlers Send\Chain::process() links, in order;
 * - `unclearGuard`: whether the chain refuses a message whose earlier send ended in STATUS_ERROR;
 * - `retriableCodes`: the Horde_Smtp_Exception codes MailTransmission records as STATUS_SMPT_SEND_FAIL;
 * - `statuses` and `typeOutgoing`: the LocalMessage constants;
 * - `findDueNeedsSendAt`: whether LocalMessageMapper::findDue(), which the background OutboxWorkerJob sends from, selects
 *   only messages with a send time;
 * - `bccEnvelope`: how the SMTP envelope gets Bcc while the transmitted headers do not (`_bcc`: Horde_Mime_Mail::send()
 *   adds its `_bcc` to the recipients; `removeHeader`: the headers drop Bcc and the recipient list adds it);
 * - `rcptRefusalBeforeData`: whether the bundled Horde_Smtp::send() throws when any recipient is refused, before DATA.
 * Any other shape stops the generator. MailSendPathContractTest checks the send tool against it. The archives are checked
 * against their SHA256 first and only read as text, never executed or unpacked to disk.
 *
 * Usage: php tests/fixtures/nextcloud-api/generate-mail-send-path.php <archive directory>
 *
 * <archive directory> holds mail-<version>.tar.gz of every release of the file.
 */

[, $archives] = $argv + [null, null];
if (!is_dir((string)$archives)) {
	fwrite(STDERR, "usage: php generate-mail-send-path.php <archive directory>\n");
	exit(2);
}
$file = __DIR__ . '/mail-send-path.json';
$releases = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

/**
 * The content of the first of these paths the archive holds.
 *
 * @param PharData $phar the archive
 * @param list<string> $paths paths inside the archive
 * @return string the content
 * @throws RuntimeException when none exists
 */
$read = static function (PharData $phar, array $paths): string {
	foreach ($paths as $path) {
		if (isset($phar[$path])) {
			return (string)$phar[$path]->getContent();
		}
	}
	throw new RuntimeException('None of ' . implode(', ', $paths));
};

foreach ($releases as $version => $release) {
	$archive = $archives . '/mail-' . $version . '.tar.gz';
	if (!is_file($archive) || hash_file('sha256', $archive) !== $release['sha256']) {
		throw new RuntimeException('Source SHA256 mismatch: ' . $archive);
	}
	$phar = new PharData($archive);
	if ((string)simplexml_load_string((string)$phar['mail/appinfo/info.xml']->getContent())->version !== $version) {
		throw new RuntimeException('Source app version mismatch in ' . $archive);
	}
	$chain = $read($phar, ['mail/lib/Send/Chain.php']);
	if (preg_match('/function process\(.*?\$handlers = \$this->(\w+);\s*\$handlers(.*?);/s', $chain, $links) !== 1) {
		throw new RuntimeException('Chain::process() not found in ' . $archive);
	}
	preg_match_all('/->setNext\(\$this->(\w+)\)/', $links[2], $next);
	$local = $read($phar, ['mail/lib/Db/LocalMessage.php']);
	preg_match_all('/public const (STATUS_\w+) = (\d+);/', $local, $statuses);
	if (preg_match('/public const TYPE_OUTGOING = (\d+);/', $local, $outgoing) !== 1) {
		throw new RuntimeException('LocalMessage::TYPE_OUTGOING not found in ' . $archive);
	}
	$transmission = $read($phar, ['mail/lib/Service/MailTransmission.php']);
	if (preg_match('/RETRIABLE_CODES = \[(.*?)\];/s', $transmission, $codes) !== 1) {
		throw new RuntimeException('RETRIABLE_CODES not found in ' . $archive);
	}
	preg_match_all('/Horde_Smtp_Exception::(\w+)/', $codes[1], $retriable);
	if (preg_match('/public function sendMessage\(Account \$account, LocalMessage \$localMessage\).*?\n\t}\n/s', $transmission, $send) !== 1) {
		throw new RuntimeException('sendMessage() not found in ' . $archive);
	}
	$mime = $read($phar, ['mail/vendor/bytestream/horde-mime/lib/Horde/Mime/Mail.php']);
	if (str_contains($send[0], "\$sendHeaders->removeHeader('Bcc');") && str_contains($send[0], '$smtpRecipients->add($bcc->toHorde());')) {
		$envelope = 'removeHeader';
	} elseif (str_contains($send[0], '$mail->addHeaders($headers);') && str_contains($send[0], '$mail->send($transport, false, false);')
		&& preg_match('/if \(\$this->_bcc\) \{\s*\$recipients->add\(\$this->_bcc\);/', $mime) === 1
		&& preg_match("/if \\(\\\$lc_header === 'bcc'\\) \\{\\s*\\\$this->_bcc = \\\$value;/", $mime) === 1) {
		$envelope = '_bcc';
	} else {
		throw new RuntimeException('Unknown Bcc handling in sendMessage() of ' . $archive);
	}
	$mapper = $read($phar, ['mail/lib/Db/LocalMessageMapper.php']);
	if (preg_match('/public function findDue\(.*?->where\((.*?)\)\s*->orderBy/s', $mapper, $due) !== 1) {
		throw new RuntimeException('findDue() not found in ' . $archive);
	}
	$smtp = $read($phar, ['mail/vendor/nextcloud/horde-smtp/lib/Horde/Smtp.php', 'mail/vendor/bytestream/horde-smtp/lib/Horde/Smtp.php']);
	$rcpt = preg_match('/Can\'t pipeline DATA since we want to throw an exception if\s*\* ANY of the recipients are bad\./', $smtp) === 1
		&& preg_match('/catch \(Horde_Smtp_Exception_Recipients \$e\) \{\s*\$e->recipients\[\] = \$key;\s*throw \$e;/', $smtp) === 1
		&& strpos($smtp, 'ANY of the recipients are bad') < strpos($smtp, "write('DATA')");
	$releases[$version]['chain'] = [$links[1], ...$next[1]];
	$releases[$version]['unclearGuard'] = preg_match('/if \(\$localMessage->getStatus\(\) === LocalMessage::STATUS_ERROR\) \{\s*throw new ServiceException/', $chain) === 1;
	$releases[$version]['retriableCodes'] = $retriable[1];
	$releases[$version]['statuses'] = array_map('intval', array_combine($statuses[1], $statuses[2]));
	$releases[$version]['typeOutgoing'] = (int)$outgoing[1];
	$releases[$version]['findDueNeedsSendAt'] = str_contains($due[1], "isNotNull('send_at')");
	$releases[$version]['bccEnvelope'] = $envelope;
	$releases[$version]['rcptRefusalBeforeData'] = $rcpt;
}
file_put_contents($file, json_encode($releases, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo 'Read the send path of Mail ' . implode(', ', array_keys($releases)) . "\n";
