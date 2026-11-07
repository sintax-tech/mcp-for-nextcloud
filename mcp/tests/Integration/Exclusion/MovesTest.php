<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Exclusion;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * V3 (real): moving a file into or out of an excluded folder from outside the tools (WebDAV) changes what the very
 * next tool call sees, and the guard of one request follows real Files and tag events.
 */
final class MovesTest extends ExclusionTestCase {
	/** @return array<string, array{string}> */
	public static function places(): array {
		return ['home' => ['home'], 'groupfolder' => ['groupfolder'], 'external storage' => ['external']];
	}

	#[DataProvider('places')]
	public function testAFileMovedByWebDavIntoAndOutOfAnExcludedFolderFollowsBetweenCalls(string $arena): void {
		$this->makeFile($arena, 'open/m.txt', 'moves around');
		$this->tag($this->makeFolder($arena, 'locked'));
		$this->exclude();
		$alice = $this->mcp();
		$dav = $this->dav();
		$open = $this->path($arena, 'open', 'm.txt');
		$locked = $this->path($arena, 'locked', 'm.txt');

		$this->assertReadable($alice, $open, 'moves around', 'before the move');
		$dav->move($open, $locked);
		$this->assertHiddenFromRead($alice, $open, 'old path after the move in');
		$this->assertHiddenFromRead($alice, $locked, 'new path inside the excluded folder');
		$this->assertNotContains('m.txt', $this->listNames($alice, $this->path($arena, 'open')) ?? ['m.txt']);
		$dav->move($locked, $open);
		$this->assertReadable($alice, $open, 'moves around', 'after the move out');
	}

	#[DataProvider('places')]
	public function testAFolderMovedByWebDavIntoAnExcludedFolderBecomesHiddenWithItsContent(string $arena): void {
		$this->makeFile($arena, 'visible/inner/f.txt', 'inside a visible folder');
		$this->tag($this->makeFolder($arena, 'locked'));
		$this->exclude();
		$alice = $this->mcp();
		$dav = $this->dav();

		$this->assertReadable($alice, $this->path($arena, 'visible', 'inner', 'f.txt'), 'inside a visible folder', 'before');
		$dav->move($this->path($arena, 'visible'), $this->path($arena, 'locked', 'visible'));
		$this->assertHiddenFromRead($alice, $this->path($arena, 'locked', 'visible', 'inner', 'f.txt'), 'content of the folder moved in');
		$dav->move($this->path($arena, 'locked', 'visible'), $this->path($arena, 'visible'));
		$this->assertReadable($alice, $this->path($arena, 'visible', 'inner', 'f.txt'), 'inside a visible folder', 'after moving it out');
	}

	public function testTheExcludedFolderKeepsItsProtectionWhereverItIsMoved(): void {
		$this->makeFile('home', 'locked/secret.txt', 'secret');
		$this->makeFolder('home', 'elsewhere');
		$this->tag($this->node(self::ALICE, $this->path('home', 'locked')));
		$this->exclude();
		$alice = $this->mcp();
		$dav = $this->dav();

		$dav->move($this->path('home', 'locked'), $this->path('home', 'elsewhere', 'renamed'));
		$this->assertHiddenFromRead($alice, $this->path('home', 'elsewhere', 'renamed', 'secret.txt'), 'content of the excluded folder after it moved');
		$this->assertNotContains('renamed', $this->listNames($alice, $this->path('home', 'elsewhere')) ?? ['renamed']);
		$dav->move($this->path('home', 'elsewhere', 'renamed'), $this->path('home', 'locked'));
		$this->assertHiddenFromRead($alice, $this->path('home', 'locked', 'secret.txt'), 'and after it came back');
	}

	public function testAMoveThroughTheToolsAndThenOneThroughWebDavAreBothSeenByTheNextCall(): void {
		$this->makeFile('home', 'open/t.txt', 'by the tool, then by dav');
		$this->tag($this->makeFolder('home', 'locked'));
		$this->makeFolder('home', 'open2');
		$this->exclude();
		$alice = $this->mcp();

		$moved = $alice->write('files_move', ['from' => $this->path('home', 'open', 't.txt'), 'to' => $this->path('home', 'open2', 't.txt')]);
		$this->assertTrue($moved->ok(), 'files_move: ' . $moved->describe());
		$this->assertReadable($alice, $this->path('home', 'open2', 't.txt'), 'by the tool, then by dav', 'after the tool moved it');
		$this->dav()->move($this->path('home', 'open2', 't.txt'), $this->path('home', 'locked', 't.txt'));
		$this->assertHiddenFromRead($alice, $this->path('home', 'locked', 't.txt'), 'after WebDAV moved it into the excluded folder');
	}

	/** The same guard instance, inside one request, follows moves and tag changes announced by real core events. */
	public function testOneGuardInstanceFollowsRealFilesAndTagEventsInTheSameRequest(): void {
		$this->makeFile('home', 'open/same.txt', 'same request');
		$locked = $this->makeFolder('home', 'locked');
		$this->tag($locked);
		$this->exclude();
		$guard = $this->newGuard(true);
		$folder = $this->userFolder(self::ALICE);

		$file = $this->node(self::ALICE, $this->path('home', 'open', 'same.txt'));
		$this->assertTrue($guard->isVisible($file), 'visible before any change');

		$moved = $file->move($folder->getFullPath(trim($this->path('home', 'locked', 'same.txt'), '/')));
		$this->assertFalse($guard->isVisible($moved), 'hidden at once after the move into the excluded folder');
		$back = $moved->move($folder->getFullPath(trim($this->path('home', 'open', 'same.txt'), '/')));
		$this->assertTrue($guard->isVisible($back), 'visible at once after the move out');

		// Tag events on an ancestor: the cached verdict of the child must not outlive the assignment.
		$parent = $this->node(self::ALICE, $this->path('home', 'open'));
		$this->assertTrue($guard->isVisible($back));
		$this->tag($parent);
		$this->assertFalse($guard->isVisible($back), 'hidden at once after the ancestor is tagged');
		$this->untag($parent);
		$this->assertTrue($guard->isVisible($back), 'visible at once after the tag is removed');
	}
}
