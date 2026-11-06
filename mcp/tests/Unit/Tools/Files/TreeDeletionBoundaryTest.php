<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files;

use PHPUnit\Framework\TestCase;

/** Recursive companion to NoFileDeletionTest, including the new handler subdirectory and the unchanged sole allowance. */
final class TreeDeletionBoundaryTest extends TestCase {
	/** The only delete remains the guarded empty folder created by that very batch, in its undo handler.
	 * @return void
	 */
	public function testNoNewHandlerMayDeleteAFileOrUndoAnUnrecordedFolder(): void {
		\OCA\Mcp\Tests\Unit\Tools\Files\Deletion\DeleteSourceBoundary::verify(dirname((new \ReflectionClass(\OCA\Mcp\Tools\Files\FilesModule::class))->getFileName()));
	}
}
