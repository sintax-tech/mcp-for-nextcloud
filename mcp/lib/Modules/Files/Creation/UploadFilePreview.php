<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Creation;

use OCP\Files\Folder;
use OCA\Mcp\Modules\Files\Creation\FileCreation;
use OCA\Mcp\Modules\Files\FilesPreviewContext;

/** Plans the operation that creates a file from an upload link, without executing the write. */
final class UploadFilePreview {
	/**
	 * @param FilesPreviewContext $context actor-root admission and node-access mapping
	 * @param ?FileCreation $creation optional inline and upload file creation service
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
		return $this->context->run($userId, fn (Folder $root): array => (new UploadFileHandler(($this->creation ?? throw new \LogicException('FileCreation is not wired'))))->plan($root, $userId, $arguments['path'], $arguments['size'] ?? null));
	}
}
