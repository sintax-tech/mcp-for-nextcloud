<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Editing;

use OCA\Mcp\Modules\Files\Handler\ReplaceFileHandler;
use OCP\Files\Folder;
use OCA\Mcp\Modules\Files\FileBackup;
use OCA\Mcp\Modules\Files\FilesCallContext;

/** Preserves the existing Files ReplaceFileAction behavior. */
final class ReplaceFileAction {
	/**
	 * @param FilesCallContext $context existing context collaborator
	 * @param FileBackup $backup existing backup collaborator
	 * @param ?ReplaceFileHandler $replaceFile existing replaceFile collaborator
	 */
	public function __construct(
		private FilesCallContext $context,
		private FileBackup $backup,
		private ?ReplaceFileHandler $replaceFile = null,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged tool result
	 */
	public function handle(array $arguments, string $userId): array {
		$confirmed = (bool)($arguments['confirm_shared'] ?? false);
		return $this->context->run($userId, fn (Folder $root): array => ($this->replaceFile ?? throw new \LogicException('ReplaceFileHandler is not wired'))->execute($root, $userId, $arguments['path'], $arguments['old'], $arguments['new'], $arguments['etag'] ?? null, $confirmed, $this->backup));
	}
}
