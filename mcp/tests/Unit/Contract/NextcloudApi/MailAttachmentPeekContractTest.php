<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi;

use OCA\Mcp\Tools\Mail\MailAttachmentSupport;
use PHPUnit\Framework\TestCase;

/**
 * The attachment tool reads through the Mail app's MessageMapper::getAttachment(), which must not mark the message as
 * read. mail-attachment-peek.json (generate-mail-attachment-peek.php) records, from the official release archives
 * pinned by SHA256, whether that method fetches the part and its MIME header with PEEK: the minimum the tool requires is
 * the first release that does, and the release just before it does not, so the minimum can be neither too low (a \Seen
 * flag set) nor needlessly high.
 */
final class MailAttachmentPeekContractTest extends TestCase {
	/** The fixture. */
	private const FILE = __DIR__ . '/../../../fixtures/nextcloud-api/mail-attachment-peek.json';

	/**
	 * The checked releases, by version.
	 *
	 * @return array<string, array{url:string, sha256:string, bodyPartPeek?:bool, mimeHeaderPeek?:bool}>
	 */
	private static function releases(): array {
		return json_decode((string)file_get_contents(self::FILE), true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * Every release is an official archive pinned by SHA256 and was checked by the generator.
	 *
	 * @return void
	 */
	public function testEveryReleaseIsPinnedAndChecked(): void {
		foreach (self::releases() as $version => $release) {
			$this->assertStringEndsWith('/v' . $version . '/mail-v' . $version . '.tar.gz', $release['url']);
			$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $release['sha256']);
			$this->assertIsBool($release['bodyPartPeek'] ?? null, "$version: run generate-mail-attachment-peek.php");
			$this->assertIsBool($release['mimeHeaderPeek'] ?? null, "$version: run generate-mail-attachment-peek.php");
		}
	}

	/**
	 * The minimum reads both with PEEK; the release just before it reads neither.
	 *
	 * @return void
	 */
	public function testTheMinimumIsTheFirstReleaseThatPeeks(): void {
		$releases = self::releases();
		$minimum = MailAttachmentSupport::MINIMUM_VERSION;
		$this->assertArrayHasKey($minimum, $releases);
		$this->assertTrue($releases[$minimum]['bodyPartPeek'] && $releases[$minimum]['mimeHeaderPeek'], "Mail $minimum peeks");
		$before = array_filter(array_keys($releases), static fn (string $version): bool => version_compare($version, $minimum, '<'));
		usort($before, 'version_compare');
		$previous = (string)end($before);
		$this->assertSame('5.10.10', $previous, 'the release just before the minimum is checked too');
		$this->assertFalse($releases[$previous]['bodyPartPeek'] || $releases[$previous]['mimeHeaderPeek'], "Mail $previous does not peek");
	}
}
