<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Files\Creation;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCP\Files\Folder;
use Throwable;

/**
 * The one place that decides whether an answer about a new file may name it. Every failure of a creation that
 * names the path or the id of the file (the file left by a failed write, a file that may exist after a failed
 * newFile, an uncertain save) asks here first, with the visibility read again: a folder or a file the rules hide is
 * never named, whatever hid it and whenever; a visibility that cannot be read keeps the answer it had.
 */
final class NewFileVisibility {
	/** The folder and the file at the name, if any, are visible: the answer may name them. */
	public const VISIBLE = 'visible';
	/** The folder or the file is hidden: the answer is opaque, without path or id. */
	public const HIDDEN = 'hidden';
	/** The visibility could not be read: the answer keeps what it would say. */
	public const UNKNOWN = 'unknown';

	/** @param FilesAccessGuard $guard the scope and visibility rules of the write */
	public function __construct(private FilesAccessGuard $guard) {
	}

	/**
	 * @param Folder $folder the destination folder
	 * @param string $name the basename of the new file
	 * @return string one of the constants of this class, read now with a cleared cache
	 */
	public function of(Folder $folder, string $name): string {
		try {
			$this->guard->clearCache();
			if (!$this->guard->isVisible($folder)) {
				return self::HIDDEN;
			}
			return !$folder->nodeExists($name) || $this->guard->isVisible($folder->get($name)) ? self::VISIBLE : self::HIDDEN;
		} catch (Throwable) {
			return self::UNKNOWN;
		}
	}
}
