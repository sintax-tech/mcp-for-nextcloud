<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Backups;

use OCA\Mcp\Access\FileTypes\FileTypeDenied;
use OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Files\Presentation\EditingMessages;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Platform\FileAccess\LockWriteFailure;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCP\Files\File;
use OCP\Lock\LockedException;

/**
 * The failure a write reports once its pre-write copy exists: the cause, closed by where the original is kept.
 *
 * The exception is a file that is no longer visible, for example because an exclusion tag was applied while the copy
 * was taken. Its copy mirrors a hidden file, so naming it would reveal the file: the answer is then the plain
 * visibility refusal, the same one a file hidden from the start gets.
 */
final class AfterCopyFailure {
	/** Static mapping only. */
	private function __construct() {
	}

	/**
	 * @param \Throwable $error What stopped the write after the copy.
	 * @param string $copy User-relative path of the copy, or empty when copies are suspended.
	 * @param File $file The file being written, checked again for visibility.
	 * @param FilesAccessGuard|null $guard Scope and exclusion tags; null where the surface has none.
	 * @return ToolFailure The failure to report. It names the copy unless the file is now hidden.
	 * @throws \OCA\Mcp\Access\Policy\PolicyUnavailable When the scope cannot be read to decide whether the file is visible.
	 */
	public static function explain(\Throwable $error, string $copy, File $file, ?FilesAccessGuard $guard): ToolFailure {
		if (!self::visible($file, $guard)) {
			return new ToolFailure(CommonMessages::notFound());
		}
		$note = EditingMessages::originalKept($copy);
		if ($error instanceof LockedException) {
			$error = NodeAccess::lockFailure($error);
			if (!$error instanceof LockWriteFailure) {
				return new ToolFailure(EditingMessages::writeFailed($copy));
			}
		}
		return match (true) {
			$error instanceof FileTypeDenied, $error instanceof FileWritePolicyUnavailable => new ToolFailure(EditingMessages::writeFailed($copy)),
			$error instanceof LockWriteFailure => $error->withNote($note),
			$error instanceof ToolFailure => new ToolFailure($error->getMessage() . ' ' . $note),
			default => new ToolFailure(EditingMessages::writeFailed($copy)),
		};
	}

	/**
	 * @param File $file The file being written.
	 * @param FilesAccessGuard|null $guard Scope and exclusion tags.
	 * @return bool Whether the file may still be shown; a check that fails for another reason counts as hidden.
	 * @throws \OCA\Mcp\Access\Policy\PolicyUnavailable When the scope cannot be read.
	 */
	private static function visible(File $file, ?FilesAccessGuard $guard): bool {
		if ($guard === null) {
			return true;
		}
		$guard->clearCache();
		try {
			return $guard->isVisible($file);
		} catch (\OCA\Mcp\Access\Policy\PolicyUnavailable $e) {
			throw $e;
		} catch (\Throwable) {
			return false;
		}
	}
}
