<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

use OCP\Files\File;
use OCP\Files\IRootFolder;
use Throwable;

/**
 * Describes the file a Talk attachment carries the way the Files tools name it: by its path below the user's own
 * folder.
 *
 * The node's own path is the storage path, which starts with the account id and the internal "files" folder. That
 * is an identifier the user never typed and the plan must not show, so the path is always taken relative to the
 * user folder, with the same native call the Files and Deck tools use. A file that cannot be placed under that
 * folder is named by its file name alone, never by the storage path.
 */
final class AttachedFile {
	/**
	 * @param IRootFolder $rootFolder Native root, used only to reach the user's own folder
	 */
	public function __construct(
		private IRootFolder $rootFolder,
	) {
	}

	/**
	 * The file as the plan and the result of talk_attach_file report it.
	 *
	 * @param string $userId Authenticated user, the owner of the folder the path is relative to
	 * @param File $file The file node, already resolved inside that folder
	 * @return array{path:string, name:string, size:int} The path below the user folder, the name and the size
	 */
	public function describe(string $userId, File $file): array {
		return [
			'path' => $this->relativePath($userId, $file),
			'name' => $file->getName(),
			'size' => (int)$file->getSize(),
		];
	}

	/**
	 * The path of the file below the user's folder, with a leading slash, as the Files tools show it.
	 *
	 * @param string $userId Authenticated user
	 * @param File $file The file node
	 * @return string The relative path, or "/" followed by the file name when the node is not under the user folder
	 */
	private function relativePath(string $userId, File $file): string {
		try {
			$relative = $this->rootFolder->getUserFolder($userId)->getRelativePath($file->getPath());
		} catch (Throwable) {
			$relative = null;
		}

		// Outside the user folder there is no path the user knows, and the storage path would reveal the account.
		return is_string($relative) && $relative !== '' ? $relative : '/' . $file->getName();
	}
}
