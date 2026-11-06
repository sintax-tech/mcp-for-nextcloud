<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Creation;

use OCP\Files\Folder;
use OCA\Mcp\Modules\Files\FileCreation;
use OCA\Mcp\Modules\Files\FilesPreviewContext;
use OCA\Mcp\Modules\Files\Handler;

/** Preserves the existing Files CreateFilePreview behavior. */
final class CreateFilePreview {
	/**
	 * @param FilesPreviewContext $context existing context collaborator
	 * @param ?FileCreation $creation existing creation collaborator
	 */
	public function __construct(
		private FilesPreviewContext $context,
		private ?FileCreation $creation = null,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged read-only plan
	 */
	public function plan(array $arguments, string $userId): array {
		$etag = $arguments['etag'] ?? null;
		return $this->context->run($userId, fn (Folder $root): array => (new Handler\CreateFileHandler(($this->creation ?? throw new \LogicException('FileCreation is not wired'))))->plan($root, $userId, $arguments['path'], $arguments['content']));
	}
}
