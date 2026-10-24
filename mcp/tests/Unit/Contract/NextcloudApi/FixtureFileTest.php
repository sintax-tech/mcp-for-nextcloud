<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi;

use PHPUnit\Framework\TestCase;

/**
 * The generators now write through FixtureFile, so it must reproduce every existing fixture byte for byte, and the
 * oldest covered release resolves to the server row for Deck and Talk and to the Mail pin for Mail.
 */
final class FixtureFileTest extends TestCase {
	/** Directory of the fixtures. */
	private const FIXTURES = __DIR__ . '/../../../fixtures/nextcloud-api';

	/**
	 * Writing a fixture's own header and data again yields the same file.
	 *
	 * @return void
	 */
	public function testEveryFixtureIsReproducedByteForByte(): void {
		foreach (array_keys(NextcloudApiUsage::SOURCES) as $major) {
			$file = self::FIXTURES . "/$major.php";
			$original = (string)file_get_contents($file);
			$copy = tempnam(sys_get_temp_dir(), 'mcp-fixture-');
			try {
				FixtureFile::write($copy, substr($original, 0, (int)strpos($original, 'return [')), require $file);
				$this->assertSame($original, file_get_contents($copy), "fixture $major");
			} finally {
				unlink($copy);
			}
		}
	}

	/**
	 * A fixture without Mail pins is written without the mailSources key.
	 *
	 * @return void
	 */
	public function testAFixtureWithoutMailPinsHasNoMailSources(): void {
		$copy = tempnam(sys_get_temp_dir(), 'mcp-fixture-');
		try {
			FixtureFile::write($copy, "<?php\n", ['sources' => ['server' => '32.0.0'], 'recipientSources' => [], 'classes' => ['OCP\\IUser' => null]]);
			$this->assertSame("<?php\nreturn [\n    'sources' => ['server' => '32.0.0'],\n    'recipientSources' => [],\n    'classes' => [\n        'OCP\\\\IUser' => NULL,\n    ],\n];\n", file_get_contents($copy));
		} finally {
			unlink($copy);
		}
	}

	/**
	 * Deck and Talk come from the server row, Mail from the fixture's pin, any other app from nowhere.
	 *
	 * @return void
	 */
	public function testTheOldestCoveredReleaseOfEachApp(): void {
		$sources = ['server' => '31.0.0', 'deck' => '1.15.0', 'spreed' => '21.1.4'];
		$fixture = ['mailSources' => ['mail' => ['version' => '5.0.0']]];

		$this->assertSame('1.15.0', OldestCoveredRelease::of($sources, $fixture, 'deck'));
		$this->assertSame('5.0.0', OldestCoveredRelease::of($sources, $fixture, 'mail'));
		$this->assertNull(OldestCoveredRelease::of($sources, [], 'mail'));
		$this->assertNull(OldestCoveredRelease::of($sources, $fixture, 'polls'));
	}
}
