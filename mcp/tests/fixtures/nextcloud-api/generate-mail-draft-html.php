<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Records the HTML draft source contract of every SHA-pinned Mail release; archives are read, never executed.
 * Usage: php generate-mail-draft-html.php <directory containing mail-VERSION.tar.gz>.
 */
[, $archives] = $argv + [null, null];
if (!is_dir((string)$archives)) {
	throw new RuntimeException('Expected an archive directory');
}
$releases = json_decode(file_get_contents(__DIR__ . '/mail-draft-bcc.json'), true, 512, JSON_THROW_ON_ERROR);
$contract = [];
foreach ($releases as $version => $pin) {
	$file = $archives . '/mail-' . $version . '.tar.gz';
	if (!is_file($file) || hash_file('sha256', $file) !== $pin['sha256']) {
		throw new RuntimeException('Archive SHA mismatch: ' . $version);
	}
	$archive = new PharData($file);
	$transmission = $archive['mail/lib/Service/MailTransmission.php']->getContent();
	if (!preg_match('/public function saveDraft\(.*?return \[\$account, \$draftsMailbox, \$newUid\];/s', $transmission, $match)) {
		throw new RuntimeException('Unknown saveDraft shape: ' . $version);
	}
	$save = $match[0];
	$indirect = str_contains($save, '$imapMessage->setContent($message->getBody());')
		&& str_contains($save, '$mail->setHtmlBody($imapMessage->getContent());');
	$direct = str_contains($save, '$mail->setHtmlBody($message->getBody());');
	$data = $archive['mail/lib/Model/NewMessageData.php']->getContent();
	$html = $archive['mail/lib/Service/Html.php']->getContent();
	$engine = $archive['mail/vendor/ezyang/htmlpurifier/library/HTMLPurifier.php']->getContent();
	$app = $archive['mail/lib/AppInfo/Application.php']->getContent();
	$autoload = $archive['mail/vendor/composer/autoload_classmap.php']->getContent();
	$constructor = preg_match('/array \$attachments = \[\],\s*(?:private )?bool \$isHtml = true/s', $data) === 1;
	$available = str_contains($autoload, "'HTMLPurifier' =>") && str_contains($autoload, "'HTMLPurifier_Config' =>")
		&& str_contains($app, "include_once __DIR__ . '/../../vendor/autoload.php';");
	$reading = str_contains($html, 'HTMLPurifier_URIScheme_data') && str_contains($html, 'HTMLPurifier_URISchemeRegistry');
	if ((!$direct && !$indirect) || !str_contains($save, '$message->isHtml()') || str_contains($save, 'sanitizeHtmlMailBody')
		|| !$constructor || !$available || !$reading || !preg_match("/const VERSION = '([0-9.]+)'/", $engine, $engineVersion)) {
		throw new RuntimeException('HTML source contract changed: ' . $version);
	}
	$contract[$version] = ['url' => $pin['url'], 'sha256' => $pin['sha256'], 'isHtmlPosition' => 8,
		'nativeDefault' => true, 'bodyPath' => $direct ? 'getBody->setHtmlBody' : 'getBody->setContent->getContent->setHtmlBody',
		'saveSanitizes' => false, 'readingRegistersImageDataScheme' => true, 'autoloadsEngine' => true,
		'purifierVersion' => $engineVersion[1]];
}
file_put_contents(__DIR__ . '/mail-draft-html.json', json_encode($contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo count($contract) . " pinned HTML draft source contracts verified\n";
