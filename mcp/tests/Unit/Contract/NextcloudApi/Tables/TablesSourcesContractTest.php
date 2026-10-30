<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Tables;

use OCA\Mcp\Service\Compat\AppEnablement;
use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\NextcloudApiUsage;
use PHPUnit\Framework\TestCase;

/**
 * The Tables surfaces of tests/fixtures/nextcloud-api come from the releases pinned in tables/tables-sources.json
 * (tables/generate-tables.php): every covered major carries them, from a Tables release MCP accepts that declares that
 * Nextcloud, beside the server release the rest of the fixture was generated from.
 */
final class TablesSourcesContractTest extends TestCase {
	/** Directory of the fixtures. */
	private const FIXTURES = __DIR__ . '/../../../../fixtures/nextcloud-api';

	/**
	 * Every covered major has its pin, and its fixture records exactly that pin and the server files of the Mail pin.
	 *
	 * @return void
	 */
	public function testEveryFixtureCarriesThePinnedTablesSources(): void {
		$pins = TablesSourcePins::all();
		$mail = json_decode((string)file_get_contents(self::FIXTURES . '/mail-sources.json'), true, 512, JSON_THROW_ON_ERROR);
		foreach (array_keys(NextcloudApiUsage::SOURCES) as $major) {
			$major = (string)$major;
			$this->assertArrayHasKey($major, $pins, "tables-sources.json has no entry for $major");
			$fixture = require self::FIXTURES . "/$major.php";
			$this->assertSame($pins[$major], $fixture['tablesSources'] ?? null, "fixture $major: run tables/generate-tables.php");
			$this->assertSame($fixture['sources']['server'], $pins[$major]['server']['version'], "fixture $major: server release");
			$this->assertSame($mail[$major]['server'], $pins[$major]['server'], "fixture $major: same server files as the Mail pin");
		}
	}

	/**
	 * No fixture is generated from a Tables release MCP refuses or that does not declare that Nextcloud (tables-behavior.json
	 * records the range of each release), and the URL names the pinned version.
	 *
	 * @return void
	 */
	public function testThePinnedTablesReleasesAreSupportedOnes(): void {
		foreach (TablesSourcePins::all() as $major => $pin) {
			$version = $pin['tables']['version'];
			$this->assertTrue(version_compare($version, AppEnablement::MINIMUM_VERSIONS['tables'], '>='), "Tables pinned for $major is older than the minimum");
			$this->assertStringEndsWith("/v$version/tables-v$version.tar.gz", $pin['tables']['url']);
			$declared = TablesBehavior::of($version)['nextcloud'];
			$this->assertTrue((int)$major >= $declared['min'] && (int)$major <= $declared['max'], "Tables $version does not declare Nextcloud $major");
			$this->assertSame($pin['tables']['sha256'], TablesBehavior::sources()[$version]['sha256'], "Tables $version: the same tarball as tables-behavior.json");
			$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $pin['tables']['sha256']);
		}
	}

	/**
	 * The oldest pin is the minimum itself, so the oldest Tables MCP accepts is the one the contract checks.
	 *
	 * @return void
	 */
	public function testTheMinimumIsTheOldestPinnedRelease(): void {
		$versions = array_map(static fn (array $pin): string => $pin['tables']['version'], TablesSourcePins::all());
		usort($versions, 'version_compare');
		$this->assertSame(AppEnablement::MINIMUM_VERSIONS['tables'], $versions[0]);
	}

	/**
	 * A source tree of another release is refused before it is parsed.
	 *
	 * @return void
	 */
	public function testAnotherReleaseInTheTreeIsRefused(): void {
		$tree = sys_get_temp_dir() . '/mcp-tables-pin-' . bin2hex(random_bytes(4));
		mkdir($tree . '/apps/tables/appinfo', recursive: true);
		file_put_contents($tree . '/apps/tables/appinfo/info.xml', '<info><version>0.9.12</version></info>');
		try {
			$this->expectExceptionMessage('Tables source version 0.9.12 does not match tables-sources.json (1.0.0 for Nextcloud 32)');
			TablesSourcePins::verified($tree, '32');
		} finally {
			unlink($tree . '/apps/tables/appinfo/info.xml');
			rmdir($tree . '/apps/tables/appinfo');
			rmdir($tree . '/apps/tables');
			rmdir($tree . '/apps');
			rmdir($tree);
		}
	}
}
