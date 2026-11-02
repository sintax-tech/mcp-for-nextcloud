<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Editing;

use OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable;
use OCA\Mcp\Access\FileTypes\FileTypeDenied;
use OCA\Mcp\Access\FileTypes\FileTypeGuard;
use OCA\Mcp\Modules\Notes\Locating\NoteNames;

/**
 * Fresh policy snapshot of the final basename of a note rename or category move.
 *
 * Callers invoke this and then perform the move themselves, so the check and the move stay on one boundary.
 */
final class NoteRenameSink {
	/**
	 * Receives the policy reader used on the rename boundary.
	 *
	 * @param FileTypeGuard|null $types Policy reader. Null skips the check and is not a bypass in production.
	 * @return void
	 */
	public function __construct(private ?FileTypeGuard $types) {
	}

	/**
	 * Refuse a destination whose basename the active policy does not allow.
	 *
	 * @param string $target Absolute destination path. Only its basename is evaluated.
	 * @return void
	 * @throws FileTypeDenied When the active policy refuses that basename.
	 * @throws FileWritePolicyUnavailable When the policy cannot be read.
	 */
	public function assertTarget(string $target): void {
		if ($this->types === null) {
			return;
		}
		$this->types->snapshot()->assertWritableName(NoteNames::leaf($target));
	}
}
