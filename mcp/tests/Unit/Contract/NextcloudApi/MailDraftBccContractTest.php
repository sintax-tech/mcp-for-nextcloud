<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi;

use OCA\Mcp\Modules\Mail\Creation\MailDraftBcc;
use PHPUnit\Framework\TestCase;

/**
 * MailDraftBcc allows Bcc in a draft only on a Mail release whose draft save keeps it. mail-draft-bcc.json
 * (generate-mail-draft-bcc.php) records, from the official archives pinned by SHA256, how
 * MailTransmission::saveDraft() handles the headers: up to 5.9.x through Horde_Mime_Mail::addHeaders() and getRaw(),
 * which drop Bcc; from 5.10.11 on through buildMimeHeaders(), which keeps it. The minimum of MailDraftBcc must be the
 * first pinned release that keeps Bcc, with every older pinned release refused and every later one allowed.
 */
final class MailDraftBccContractTest extends TestCase {
	/**
	 * The checked releases, by version.
	 *
	 * @return array<string, array{url:string, sha256:string, saveDraft?:string, bccKept?:bool}>
	 */
	private static function releases(): array {
		return json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/nextcloud-api/mail-draft-bcc.json'), true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * Each release is pinned like the other Mail contracts and was read by the generator; the pins of the main fixtures
	 * (5.0.0 to 5.9.0) are among them.
	 *
	 * @return void
	 */
	public function testEveryReleaseIsPinnedAndRead(): void {
		$releases = self::releases();
		foreach ($releases as $version => $release) {
			$this->assertStringEndsWith('/v' . $version . '/mail-v' . $version . '.tar.gz', $release['url']);
			$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $release['sha256']);
			$this->assertIsBool($release['bccKept'] ?? null, "$version: run generate-mail-draft-bcc.php");
		}
		$sources = json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/nextcloud-api/mail-sources.json'), true, 512, JSON_THROW_ON_ERROR);
		foreach ($sources as $major => $source) {
			$this->assertSame($source['mail']['sha256'], $releases[$source['mail']['version']]['sha256'] ?? null, "NC$major Mail pin");
		}
	}

	/**
	 * The minimum is the first release that keeps Bcc; MailDraftBcc::kept() refuses every older release and allows every
	 * later one, and refuses a version it cannot read.
	 *
	 * @return void
	 */
	public function testTheMinimumIsTheFirstReleaseThatKeepsBcc(): void {
		$releases = self::releases();
		uksort($releases, 'version_compare');
		$first = null;
		foreach ($releases as $version => $release) {
			$first ??= $release['bccKept'] ? (string)$version : null;
			$this->assertSame($release['bccKept'], MailDraftBcc::kept((string)$version), "Mail $version");
			$this->assertSame($release['bccKept'] ? 'buildMimeHeaders+toString' : 'addHeaders+getRaw', $release['saveDraft'], "Mail $version");
		}
		$this->assertSame(MailDraftBcc::MINIMUM_VERSION, $first);
		// A version not of the x.y.z form the Mail app always has is unreadable, even where version_compare() alone would
		// rank it above the minimum.
		foreach (['', 'unknown', 'dev-main', '6', '9.0'] as $unreadable) {
			$this->assertFalse(MailDraftBcc::kept($unreadable), "'$unreadable'");
		}
	}
}
