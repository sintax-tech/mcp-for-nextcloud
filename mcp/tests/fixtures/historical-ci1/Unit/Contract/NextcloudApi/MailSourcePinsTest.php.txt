<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A generator only parses a Mail app that is exactly the pinned release: the check accepts the matching tree and
 * refuses another release or none, and generate.php itself stops on a mismatched tree before it writes anything.
 */
final class MailSourcePinsTest extends TestCase {
	/** Directory of the fixtures. */
	private const FIXTURES = __DIR__ . '/../../../fixtures/nextcloud-api';

	/** @var list<string> temporary trees of this test */
	private array $trees = [];

	/**
	 * Removes the temporary trees.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->trees as $tree) {
			$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tree, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
			foreach ($files as $file) {
				$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
			}
			rmdir($tree);
		}
		$this->trees = [];
		parent::tearDown();
	}

	/**
	 * The tree holding the pinned release yields the pin.
	 *
	 * @return void
	 */
	public function testTheMatchingReleaseYieldsThePin(): void {
		$tree = $this->tree(['mail' => '5.0.0']);

		$this->assertSame(MailSourcePins::pin('32'), MailSourcePins::verified($tree, '32'));
		$this->assertSame('5.0.0', MailSourcePins::verified($tree, '32')['mail']['version']);
	}

	/**
	 * Another release is refused, whatever the direction of the difference.
	 *
	 * @return void
	 */
	public function testAnotherReleaseIsRefused(): void {
		$tree = $this->tree(['mail' => '5.12.5']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Mail source version 5.12.5 does not match mail-sources.json (5.0.0 for Nextcloud 32)');
		MailSourcePins::verified($tree, '32');
	}

	/**
	 * A tree without the Mail app is refused.
	 *
	 * @return void
	 */
	public function testATreeWithoutMailIsRefused(): void {
		$tree = $this->tree([]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Mail source version missing does not match mail-sources.json');
		MailSourcePins::verified($tree, '35');
	}

	/**
	 * A major without a pin is refused.
	 *
	 * @return void
	 */
	public function testAMajorWithoutPinIsRefused(): void {
		$this->expectException(RuntimeException::class);
		MailSourcePins::pin('99');
	}

	/**
	 * generate.php, given a tree whose server, Deck, Talk and Circles match the NC32 row but whose Mail is 5.12.5,
	 * stops with the Mail mismatch and leaves the fixture untouched.
	 *
	 * @return void
	 */
	public function testTheGeneratorStopsOnAMismatchedMailBeforeWriting(): void {
		$tree = $this->tree(['deck' => '1.16.0', 'spreed' => '22.0.0', 'circles' => '32.0.0', 'mail' => '5.12.5']);
		file_put_contents($tree . '/version.php', "<?php\n\$OC_VersionString = '32.0.0';\n");
		$fixture = self::FIXTURES . '/32.php';
		$before = hash_file('sha256', $fixture);

		$process = proc_open([PHP_BINARY, self::FIXTURES . '/generate.php', '32', $tree], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$this->assertIsResource($process);
		$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status = proc_close($process);

		$this->assertNotSame(0, $status, $output);
		$this->assertStringContainsString('Mail source version 5.12.5 does not match mail-sources.json (5.0.0 for Nextcloud 32)', $output);
		$this->assertStringNotContainsString('wrote 32.php', $output);
		$this->assertSame($before, hash_file('sha256', $fixture), 'the fixture was rewritten');
	}

	/**
	 * A temporary tree holding an appinfo/info.xml for each app.
	 *
	 * @param array<string, string> $apps version of each app, by app id
	 * @return string the tree
	 */
	private function tree(array $apps): string {
		$tree = sys_get_temp_dir() . '/mcp-mail-pins-' . bin2hex(random_bytes(6));
		mkdir($tree);
		$this->trees[] = $tree;
		foreach ($apps as $app => $version) {
			mkdir("$tree/apps/$app/appinfo", recursive: true);
			file_put_contents("$tree/apps/$app/appinfo/info.xml", "<?xml version=\"1.0\"?>\n<info><id>$app</id><version>$version</version></info>\n");
		}
		return $tree;
	}
}
