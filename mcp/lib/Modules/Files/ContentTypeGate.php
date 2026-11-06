<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files;

use OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable;
use OCA\Mcp\Access\FileTypes\FileTypeDenied;
use OCA\Mcp\Access\FileTypes\FileTypeGuard;
use OCP\Files\File;

/**
 * One policy snapshot for the current basename of a file that edit, replace or restore is about to write.
 *
 * A missing guard is not a mode: production always injects {@see FileTypeGuard}, and an unreadable policy
 * refuses the write ({@see FileWritePolicyUnavailable}) instead of behaving as if the policy were off.
 */
class ContentTypeGate {
	/**
	 * @param FileTypeGuard $guard Policy reader. One snapshot per call, never cached across boundaries.
	 */
	public function __construct(private FileTypeGuard $guard) {
	}

	/**
	 * Authorizes `$file`'s current basename. The version's old name and its bytes are not consulted.
	 *
	 * @param File $file File whose current name would be written.
	 * @return void
	 * @throws FileTypeDenied When the active allowlist refuses that name.
	 * @throws FileWritePolicyUnavailable When the policy cannot be read or validated.
	 */
	public function assertCurrentName(File $file): void {
		$this->guard->snapshot()->assertWritableName($file->getName());
	}
}
