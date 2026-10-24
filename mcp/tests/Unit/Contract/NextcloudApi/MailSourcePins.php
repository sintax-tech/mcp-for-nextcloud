<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi;

use RuntimeException;

/**
 * The Mail release each fixture of tests/fixtures/nextcloud-api is generated from (mail-sources.json), and the check
 * a generator runs before it parses a source tree: the Mail app under apps/mail must be exactly that release, so a
 * fixture can never record one Mail version while carrying the surface of another.
 */
final class MailSourcePins {
	/** The pins file, next to the fixtures. */
	public const FILE = __DIR__ . '/../../../fixtures/nextcloud-api/mail-sources.json';

	/**
	 * The pin of one major.
	 *
	 * @param string $major Nextcloud major of the fixture
	 * @return array{mail:array{version:string, url:string, sha256:string}, server:array{version:string, files:array<string, string>}} the pin
	 * @throws RuntimeException when mail-sources.json has no entry for the major
	 */
	public static function pin(string $major): array {
		$pins = json_decode((string)file_get_contents(self::FILE), true, 512, JSON_THROW_ON_ERROR);
		if (!isset($pins[$major])) {
			throw new RuntimeException("mail-sources.json has no entry for Nextcloud $major");
		}
		return $pins[$major];
	}

	/**
	 * The pin of one major, once the Mail app of the source tree is checked to be that very release.
	 *
	 * @param string $tree source tree with the Mail app under apps/mail
	 * @param string $major Nextcloud major of the fixture
	 * @return array{mail:array{version:string, url:string, sha256:string}, server:array{version:string, files:array<string, string>}} the pin
	 * @throws RuntimeException when the tree holds another Mail release, or none
	 */
	public static function verified(string $tree, string $major): array {
		$pin = self::pin($major);
		$manifest = $tree . '/apps/mail/appinfo/info.xml';
		$found = is_file($manifest) ? (string)(simplexml_load_file($manifest)->version ?? '') : '';
		if ($found !== $pin['mail']['version']) {
			throw new RuntimeException('Mail source version ' . ($found === '' ? 'missing' : $found)
				. ' does not match mail-sources.json (' . $pin['mail']['version'] . " for Nextcloud $major)");
		}
		return $pin;
	}
}
