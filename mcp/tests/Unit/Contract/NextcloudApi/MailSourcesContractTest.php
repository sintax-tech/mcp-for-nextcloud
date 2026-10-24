<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi;

use OCA\Mcp\Service\Compat\AppEnablement;
use PHPUnit\Framework\TestCase;

/**
 * The Mail surfaces of tests/fixtures/nextcloud-api come from the releases pinned in mail-sources.json
 * (generate-mail.php): every covered major carries them, from a Mail release MCP accepts, beside the server
 * release the rest of the fixture was generated from.
 */
final class MailSourcesContractTest extends TestCase {
	/** Directory of the fixtures. */
	private const FIXTURES = __DIR__ . '/../../../fixtures/nextcloud-api';

	/**
	 * The pins of mail-sources.json, by major.
	 *
	 * @return array<string, array{mail:array{version:string, url:string, sha256:string}, server:array{version:string, files:array<string, string>}}>
	 */
	private static function pins(): array {
		return json_decode((string)file_get_contents(self::FIXTURES . '/mail-sources.json'), true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * Every covered major has its pin, and its fixture records exactly that pin.
	 *
	 * @return void
	 */
	public function testEveryFixtureCarriesThePinnedMailSources(): void {
		$pins = self::pins();
		foreach (array_keys(NextcloudApiUsage::SOURCES) as $major) {
			$major = (string)$major;
			$this->assertArrayHasKey($major, $pins, "mail-sources.json has no entry for $major");
			$fixture = require self::FIXTURES . "/$major.php";
			$this->assertSame($pins[$major], $fixture['mailSources'] ?? null, "fixture $major: run generate-mail.php");
			$this->assertSame($fixture['sources']['server'], $pins[$major]['server']['version'], "fixture $major: server release");
		}
	}

	/**
	 * No fixture is generated from a Mail release MCP refuses, and the URL names the pinned version.
	 *
	 * @return void
	 */
	public function testThePinnedMailReleasesAreSupportedOnes(): void {
		foreach (self::pins() as $major => $pin) {
			$this->assertTrue(version_compare($pin['mail']['version'], AppEnablement::MINIMUM_VERSIONS['mail'], '>='), "Mail pinned for $major is older than the minimum");
			$this->assertStringEndsWith('/v' . $pin['mail']['version'] . '/mail-v' . $pin['mail']['version'] . '.tar.gz', $pin['mail']['url']);
		}
	}

	/**
	 * The oldest pin is the minimum itself, so the oldest Mail MCP accepts is the one the contract checks.
	 *
	 * @return void
	 */
	public function testTheMinimumIsTheOldestPinnedRelease(): void {
		$versions = array_map(static fn (array $pin): string => $pin['mail']['version'], self::pins());
		usort($versions, 'version_compare');
		$this->assertSame(AppEnablement::MINIMUM_VERSIONS['mail'], $versions[0]);
	}
}
