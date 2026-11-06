<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Deletion;

use OCP\Files\Folder;
use OCA\Mcp\Modules\Files\FilesPreviewContext;

/** Plans the operation that deletes a file through the guarded deletion handler, without executing the write. */
final class DeleteFilePreview {
	/**
	 * @param FilesPreviewContext $context actor-root admission and node-access mapping
	 * @param ?DeleteFileHandler $deleteFile optional guarded file-deletion handler
	 */
	public function __construct(
		private FilesPreviewContext $context,
		private ?DeleteFileHandler $deleteFile = null,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged read-only plan
	 */
	public function plan(array $arguments, string $userId): array {
		return $this->context->run($userId, fn (Folder $root): array => $this->deleteFile->plan($root, $userId, $arguments));
	}
}
