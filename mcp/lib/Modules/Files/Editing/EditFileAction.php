<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Editing;

use OCA\Mcp\Modules\Files\Handler\EditFileHandler;
use OCP\Files\Folder;
use OCA\Mcp\Modules\Files\Backups\FileBackup;
use OCA\Mcp\Modules\Files\FilesCallContext;

/** Runs the operation that replaces file content through the guarded edit handler inside the actor view. */
final class EditFileAction {
	/**
	 * @param FilesCallContext $context actor-root admission and node-access mapping
	 * @param FileBackup $backup pre-write backup service
	 * @param ?EditFileHandler $editFile optional guarded content-edit handler
	 */
	public function __construct(
		private FilesCallContext $context,
		private FileBackup $backup,
		private ?EditFileHandler $editFile = null,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged tool result
	 */
	public function handle(array $arguments, string $userId): array {
		$confirmed = (bool)($arguments['confirm_shared'] ?? false);
		return $this->context->run($userId, fn (Folder $root): array => ($this->editFile ?? throw new \LogicException('EditFileHandler is not wired'))->execute($root, $userId, $arguments['path'], $arguments['content'], $arguments['etag'] ?? null, $confirmed, $this->backup));
	}
}
