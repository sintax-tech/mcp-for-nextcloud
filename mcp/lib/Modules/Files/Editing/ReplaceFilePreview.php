<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Editing;

use OCA\Mcp\Modules\Files\Handler\ReplaceFileHandler;
use OCP\Files\Folder;
use OCA\Mcp\Modules\Files\FilesPreviewContext;

/** Preserves the existing Files ReplaceFilePreview behavior. */
final class ReplaceFilePreview {
	/**
	 * @param FilesPreviewContext $context existing context collaborator
	 * @param ?ReplaceFileHandler $replaceFile existing replaceFile collaborator
	 */
	public function __construct(
		private FilesPreviewContext $context,
		private ?ReplaceFileHandler $replaceFile = null,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged read-only plan
	 */
	public function plan(array $arguments, string $userId): array {
		$etag = $arguments['etag'] ?? null;
		return $this->context->run($userId, fn (Folder $root): array => ($this->replaceFile ?? throw new \LogicException('ReplaceFileHandler is not wired'))->plan($root, $userId, $arguments['path'], $arguments['old'], $arguments['new'], $etag));
	}
}
