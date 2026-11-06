<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Deletion;

use OCP\Files\Folder;
use OCA\Mcp\Modules\Files\FilesPreviewContext;
use OCA\Mcp\Modules\Files\Handler;

/** Preserves the existing Files DeleteFilePreview behavior. */
final class DeleteFilePreview {
	/**
	 * @param FilesPreviewContext $context existing context collaborator
	 * @param ?Handler\DeleteFileHandler $deleteFile existing deleteFile collaborator
	 */
	public function __construct(
		private FilesPreviewContext $context,
		private ?Handler\DeleteFileHandler $deleteFile = null,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged read-only plan
	 */
	public function plan(array $arguments, string $userId): array {
		$etag = $arguments['etag'] ?? null;
		return $this->context->run($userId, fn (Folder $root): array => $this->deleteFile->plan($root, $userId, $arguments));
	}
}
