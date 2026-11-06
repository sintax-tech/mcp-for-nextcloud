<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Editing;

use OCA\Mcp\Modules\Files\Handler\EditFileHandler;
use OCP\Files\Folder;
use OCA\Mcp\Modules\Files\FilesPreviewContext;

/** Preserves the existing Files EditFilePreview behavior. */
final class EditFilePreview {
	/**
	 * @param FilesPreviewContext $context existing context collaborator
	 * @param ?EditFileHandler $editFile existing editFile collaborator
	 */
	public function __construct(
		private FilesPreviewContext $context,
		private ?EditFileHandler $editFile = null,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged read-only plan
	 */
	public function plan(array $arguments, string $userId): array {
		$etag = $arguments['etag'] ?? null;
		return $this->context->run($userId, fn (Folder $root): array => ($this->editFile ?? throw new \LogicException('EditFileHandler is not wired'))->plan($root, $userId, $arguments['path'], $arguments['content'], $etag));
	}
}
