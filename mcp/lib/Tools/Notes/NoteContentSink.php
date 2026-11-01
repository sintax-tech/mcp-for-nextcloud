<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Notes;

use Closure;
use OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable;
use OCA\Mcp\Access\FileTypes\FileTypeDenied;
use OCA\Mcp\Access\FileTypes\FileTypeGuard;
use OCP\Files\File;

/**
 * Puts note bytes only after a fresh policy snapshot of every basename this edit would leave behind.
 *
 * The preflight that ran earlier is a different boundary. This snapshot is the one next to putContent, and it
 * covers the current name and the projected final name together. The rename keeps its own later check.
 */
final class NoteContentSink {
	/**
	 * Receives the policy reader used on the content boundary.
	 *
	 * @param FileTypeGuard|null $types Policy reader. Null skips the check and is not a bypass in production.
	 * @return void
	 */
	public function __construct(private ?FileTypeGuard $types) {
	}

	/**
	 * Whole-content write. The snapshot of the whole set is taken inside the closure, immediately before putContent.
	 *
	 * @param File $note Note that receives the bytes under its current name.
	 * @param string $content Full replacement content already size-checked by the caller.
	 * @param list<string> $basenames Current name and, when the title changes, the final basename.
	 * @return Closure():void Write to run inside the caller's lock check.
	 * @throws FileTypeDenied When the closure runs and the active policy refuses one of the names.
	 * @throws FileWritePolicyUnavailable When the closure runs and the policy cannot be read.
	 */
	public function whole(File $note, string $content, array $basenames): Closure {
		return function () use ($note, $content, $basenames): void {
			$this->assertSink($basenames);
			$note->putContent($content);
		};
	}

	/**
	 * Append or replace. The approved-plan recheck runs first and writes nothing; one new snapshot of the whole
	 * set is the boundary immediately before putContent.
	 *
	 * @param File $note Note that receives the bytes under its current name.
	 * @param string $content Content already produced from the approved plan.
	 * @param Closure():void $recheck Rereads the note and refuses with a new plan when the approval is stale.
	 * @param list<string> $basenames Current name and, when the title changes, the final basename.
	 * @return Closure():void Write to run inside the caller's lock check.
	 * @throws FileTypeDenied When the closure runs and the active policy refuses one of the names.
	 * @throws FileWritePolicyUnavailable When the closure runs and the policy cannot be read.
	 */
	public function partial(File $note, string $content, Closure $recheck, array $basenames): Closure {
		return function () use ($note, $content, $recheck, $basenames): void {
			$recheck();
			$this->assertSink($basenames);
			$note->putContent($content);
		};
	}

	/**
	 * Reads the policy once and applies that immutable snapshot to every basename of this content write.
	 *
	 * @param list<string> $basenames Names this write would leave behind. Never paths.
	 * @return void
	 * @throws FileTypeDenied When the active policy refuses one of the names.
	 * @throws FileWritePolicyUnavailable When the policy cannot be read.
	 */
	private function assertSink(array $basenames): void {
		(new NoteTypeBoundary($this->types))->assertProjected($basenames);
	}
}
