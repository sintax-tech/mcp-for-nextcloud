<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Exclusion;

use OCA\Mcp\Tests\Integration\Support\McpClient;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * V4 (real): a file or subfolder that alice shares with bob is judged by the chain of alice's own tree, not by the
 * mount bob sees: a tag on the file, on its immediate parent or on an ancestor far above the share root hides it for
 * bob, with a real share created through the share manager and read by bob through his own login.
 */
final class SharedOriginTest extends ExclusionTestCase {
	private const FILE = 'proj/inner/f.txt';
	private const DEEP = 'proj/inner/deep/g.txt';

	/** @return array<string, array{string, string, list<string>, list<string>}> share kind, tagged path of alice, hidden paths of bob, visible paths of bob */
	public static function cases(): array {
		return [
			'file share, tag on the file' => ['file', 'proj/inner/f.txt', ['/f.txt'], []],
			'file share, tag on the immediate parent' => ['file', 'proj/inner', ['/f.txt'], []],
			'file share, tag on a high ancestor' => ['file', 'proj', ['/f.txt'], []],
			'folder share, tag on a high ancestor' => ['folder', 'proj', ['/inner', '/inner/f.txt', '/inner/deep/g.txt'], []],
			'folder share, tag on the shared folder itself' => ['folder', 'proj/inner', ['/inner', '/inner/f.txt', '/inner/deep/g.txt'], []],
			'folder share, tag on a subfolder inside it' => ['folder', 'proj/inner/deep', ['/inner/deep', '/inner/deep/g.txt'], ['/inner/f.txt']],
			'folder share, tag on a file inside it' => ['folder', 'proj/inner/f.txt', ['/inner/f.txt'], ['/inner/deep/g.txt']],
		];
	}

	/**
	 * @param list<string> $hidden
	 * @param list<string> $visible
	 */
	#[DataProvider('cases')]
	public function testTheOwnersChainDecidesWhatTheRecipientSees(string $share, string $tagged, array $hidden, array $visible): void {
		$this->makeFile('home', self::FILE, 'file content');
		$this->makeFile('home', self::DEEP, 'deep content');
		$shared = $this->node(self::ALICE, $this->path('home', $share === 'file' ? self::FILE : 'proj/inner'));
		$this->shareToUser($shared, self::ALICE, self::BOB);
		$this->exclude();
		$bob = $this->mcp(self::BOB);

		$everything = array_merge($hidden, $visible);
		foreach ($everything as $path) {
			$this->assertReachable($bob, $path, 'before any tag');
		}

		$target = $this->node(self::ALICE, $this->path('home', $tagged));
		$this->tag($target);
		foreach ($hidden as $path) {
			$this->assertRefusedAsMissing($bob, $this->reach($bob, $path), "bob at $path with $tagged tagged");
			if (substr_count($path, '/') === 1) {
				$this->assertNotContains(substr($path, 1), $this->listNames($bob, '/') ?? [], "$path is not listed in the root of bob");
			}
		}
		foreach ($visible as $path) {
			$this->assertReachable($bob, $path, "outside the tagged branch ($tagged tagged)");
		}

		$this->untag($target);
		foreach ($everything as $path) {
			$this->assertReachable($bob, $path, 'after the tag is removed');
		}
	}

	/**
	 * A share bob received and then moved into a folder of his own that is tagged: the share is outside what the
	 * owner's chain says, and the folder above its mount point (bob's) must still be checked.
	 *
	 * @return array<string, array{string}>
	 */
	public static function receivedShareKinds(): array {
		return ['file share' => ['file'], 'folder share' => ['folder']];
	}

	#[DataProvider('receivedShareKinds')]
	public function testAShareMovedByTheRecipientIntoOneOfTheirTaggedFoldersIsHidden(string $kind): void {
		$this->makeFile('home', self::FILE, 'file content');
		$shared = $this->node(self::ALICE, $this->path('home', $kind === 'file' ? self::FILE : 'proj/inner'));
		$this->shareToUser($shared, self::ALICE, self::BOB, true);
		$this->exclude();
		$bob = $this->mcp(self::BOB);
		$dav = $this->dav(self::BOB);
		$shareName = $kind === 'file' ? 'f.txt' : 'inner';
		$reachable = $kind === 'file' ? '/f.txt' : '/inner/f.txt';
		$bobsFolder = 'locked-' . $this->run;
		$this->later(fn () => $this->deleteQuietly(self::BOB, $bobsFolder));

		$this->assertReadable($bob, $reachable, 'file content', 'the share where it was received');
		$dav->mkcol('/' . $bobsFolder);
		$dav->move('/' . $shareName, '/' . $bobsFolder . '/' . $shareName);
		$moved = '/' . $bobsFolder . ($kind === 'file' ? '/f.txt' : '/inner/f.txt');
		$this->assertReadable($bob, $moved, 'file content', 'the share after bob moved it into an untagged folder of his own');

		$folder = $this->node(self::BOB, $bobsFolder);
		$this->tag($folder);
		$this->assertHiddenFromRead($bob, $moved, 'the share moved into a folder of bob that is tagged');
		$this->untag($folder);
		$this->assertReadable($bob, $moved, 'file content', 'after bob\'s folder is untagged');
	}

	private function reach(McpClient $client, string $path): \OCA\Mcp\Tests\Integration\Support\ToolReply {
		return str_ends_with($path, '.txt')
			? $client->tool('files_read', ['path' => $path])
			: $client->tool('files_list', ['path' => $path]);
	}

	private function assertReachable(McpClient $client, string $path, string $when): void {
		$reply = $this->reach($client, $path);
		$this->assertTrue($reply->ok(), "$path must be reachable $when: " . $reply->describe());
	}
}
