<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Exclusion;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Positive controls (inherited from the A1 review, F4): the guard refuses any node whose chain of ancestors does not
 * end at the root folder, so a real mount that broke that assumption would hide every file and the negative scenarios
 * would pass for the wrong reason. With an exclusion configured (a tag exists and is listed, but nothing under test
 * carries it), a plain file must stay readable and listed in the home, in a share received by another user, in a
 * groupfolder and in each local external storage.
 */
final class PositiveControlsTest extends ExclusionTestCase {
	/** @return array<string, array{string}> */
	public static function places(): array {
		return ['home' => ['home'], 'groupfolder' => ['groupfolder'], 'external storage' => ['external'],
			'external alias' => ['alias'], 'external narrow mount' => ['narrow']];
	}

	#[DataProvider('places')]
	public function testAnUnexcludedFileStaysVisibleWhileAnExclusionIsConfigured(string $arena): void {
		$this->makeFile($arena, 'ctl.txt', "control of $arena");
		$this->makeFile('home', 'tagged-elsewhere.txt', 'tagged elsewhere');
		$this->tag($this->node(self::ALICE, $this->path('home', 'tagged-elsewhere.txt')));
		$this->exclude();

		$alice = $this->mcp();
		$this->assertReadable($alice, $this->path($arena, 'ctl.txt'), "control of $arena", "$arena control file");
		$this->assertContains('ctl.txt', $this->listNames($alice, $this->path($arena)) ?? [], "$arena control file is listed");
		$this->assertHiddenFromRead($alice, $this->path('home', 'tagged-elsewhere.txt'), 'the tagged file of the same run');
	}

	public function testAFileSharedToAnotherUserStaysVisibleToThem(): void {
		$name = 'ctl-share-' . $this->run . '.txt';
		$file = $this->makeFile('home', $name, 'shared control');
		$this->shareToUser($file, self::ALICE, self::BOB);
		$this->makeFile('home', 'tagged-elsewhere.txt', 'x');
		$this->tag($this->node(self::ALICE, $this->path('home', 'tagged-elsewhere.txt')));
		$this->exclude();

		$bob = $this->mcp(self::BOB);
		$this->assertReadable($bob, '/' . $name, 'shared control', 'file shared to bob');
		$this->assertContains($name, $this->listNames($bob, '/') ?? [], 'the share is listed in the root of bob');
	}

	public function testAFolderSharedToAnotherUserStaysVisibleToThem(): void {
		$folder = 'ctl-folder-' . $this->run;
		$this->makeFile('home', $folder . '/inner/deep.txt', 'deep control');
		$this->shareToUser($this->node(self::ALICE, $this->path('home', $folder)), self::ALICE, self::BOB);
		$this->makeFile('home', 'tagged-elsewhere.txt', 'x');
		$this->tag($this->node(self::ALICE, $this->path('home', 'tagged-elsewhere.txt')));
		$this->exclude();

		$bob = $this->mcp(self::BOB);
		$this->assertReadable($bob, '/' . $folder . '/inner/deep.txt', 'deep control', 'file inside a folder shared to bob');
		$this->assertContains('inner', $this->listNames($bob, '/' . $folder) ?? [], 'the shared folder is listed');
	}

	public function testAGroupfolderFileSharedToAnotherUserStaysVisibleToThem(): void {
		$name = 'ctl-gf-share-' . $this->run . '.txt';
		$file = $this->makeFile('groupfolder', $name, 'groupfolder shared control');
		$this->shareToUser($file, self::ALICE, self::BOB);
		$this->makeFile('home', 'tagged-elsewhere.txt', 'x');
		$this->tag($this->node(self::ALICE, $this->path('home', 'tagged-elsewhere.txt')));
		$this->exclude();

		$this->assertReadable($this->mcp(self::BOB), '/' . $name, 'groupfolder shared control', 'groupfolder file shared to bob');
	}

	public function testWithoutAnyExclusionEverythingIsVisibleToo(): void {
		$this->makeFile('home', 'plain.txt', 'plain');
		$this->assertReadable($this->mcp(), $this->path('home', 'plain.txt'), 'plain', 'no exclusion configured');
	}
}
