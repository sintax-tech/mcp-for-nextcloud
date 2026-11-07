<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi;

use OCA\Mcp\Modules\Mail\Sending\MailSendBccCopy;
use PHPUnit\Framework\TestCase;

/**
 * The plan of a send says the copy in Sent will not show Bcc below MailSendBccCopy::MINIMUM_VERSION. mail-send-path.json
 * (generate-mail-send-path.php) records from the SHA256-pinned sources how each release sends: `_bcc` (Horde_Mime_Mail,
 * whose getRaw() saved in Sent has no Bcc line) or `removeHeader` (separate headers for the copy, Bcc included). The
 * minimum must be the first `removeHeader` release, with every older one `_bcc` and every later one `removeHeader`.
 */
final class MailSendBccCopyContractTest extends TestCase {
	/**
	 * The minimum is the first release whose copy in Sent keeps Bcc.
	 *
	 * @return void
	 */
	public function testTheMinimumIsTheFirstReleaseWhoseCopyKeepsBcc(): void {
		$releases = json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/nextcloud-api/mail-send-path.json'), true, 512, JSON_THROW_ON_ERROR);
		uksort($releases, 'version_compare');
		$first = null;
		foreach ($releases as $version => $release) {
			$first ??= $release['bccEnvelope'] === 'removeHeader' ? (string)$version : null;
			$expected = version_compare((string)$version, MailSendBccCopy::MINIMUM_VERSION, '>=') ? 'removeHeader' : '_bcc';
			$this->assertSame($expected, $release['bccEnvelope'], "Mail $version");
		}
		$this->assertSame(MailSendBccCopy::MINIMUM_VERSION, $first);
	}
}
