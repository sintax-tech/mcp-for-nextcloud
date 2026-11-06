<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Deletion;

use OCP\Files\Folder;
use OCA\Mcp\Modules\Files\FilesCallContext;
use OCA\Mcp\Modules\Files\Handler;

/** Runs the operation that deletes a file through the guarded deletion handler inside the actor view. */
final class DeleteFileAction {
	/**
	 * @param FilesCallContext $context actor-root admission and node-access mapping
	 * @param ?Handler\DeleteFileHandler $deleteFile optional guarded file-deletion handler
	 */
	public function __construct(
		private FilesCallContext $context,
		private ?Handler\DeleteFileHandler $deleteFile = null,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged tool result
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->context->run($userId, fn (Folder $root): array => $this->deleteFile->handle($root, $userId, $arguments));
	}
}
