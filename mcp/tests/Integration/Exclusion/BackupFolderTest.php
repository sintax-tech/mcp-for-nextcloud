<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Exclusion;

use OCA\Mcp\Service\VisibilityGuard;
use PHPUnit\Framework\Attributes\DataProvider;

/** Real personal, external, groupfolder and received-share backup-location regressions. */
final class BackupFolderTest extends ExclusionTestCase {
	/** @return iterable<string, array{string}> arenas provisioned by the official harness */
	public static function arenasForNamesakes(): iterable {
		yield 'personal subfolder' => ['home'];
		yield 'external mount' => ['external'];
		yield 'groupfolder mount' => ['groupfolder'];
	}

	/**
	 * A namesake under a normal directory must remain visible despite a hidden sibling original.
	 *
	 * @param string $arena provisioned storage arena
	 * @return void
	 */
	#[DataProvider('arenasForNamesakes')]
	public function testNestedNamesakeIsOrdinary(string $arena): void {
		$original = $this->makeFile($arena, 'secret.txt', 'private');
		$copy = $this->makeFile($arena, 'MCP backups/secret.txt.20261024-120000.bak', 'ordinary');
		$this->tag($original);
		$this->exclude();
		self::assertTrue($this->newGuard()->isVisible($copy));
	}

	/**
	 * The actual root backup keeps inheriting the hidden original's exclusion.
	 *
	 * @return void
	 */
	public function testRootMirrorStillHidesAnExcludedOriginal(): void {
		$original = $this->makeFile('home', 'secret.txt', 'private');
		$relative = 'MCP backups/' . $this->run;
		$this->later(fn () => $this->deleteQuietly(self::ALICE, $relative));
		$copy = $this->ensureFolder(self::ALICE, $relative)->newFile('secret.txt.20261024-120000.bak', 'copy');
		$this->tag($original);
		$this->exclude();
		self::assertFalse($this->newGuard()->isVisible($copy));
		self::assertSame(VisibilityGuard::EXPLAINED_BACKUP, $this->newGuard()->explain($copy)['reason']);
	}

	/**
	 * A folder called MCP backups inside a received share retains ordinary folder semantics.
	 *
	 * @return void
	 */
	public function testNamesakeInsideReceivedShareIsOrdinary(): void {
		$original = $this->makeFile('home', 'secret.txt', 'private');
		$this->makeFile('home', 'MCP backups/secret.txt.20261024-120000.bak', 'ordinary');
		$this->shareToUser($this->userFolder(self::ALICE)->get($this->run), self::ALICE, self::BOB);
		$this->tag($original);
		$this->exclude();
		self::assertContains('secret.txt.20261024-120000.bak', $this->listNames($this->mcp(self::BOB), '/' . $this->run . '/MCP backups') ?? [], 'the ordinary file is listed inside the received share');
	}
}
