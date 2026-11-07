<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Creation;

use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\Files\Folder;
use OCA\Mcp\Modules\Files\FilesCallContext;
use OCA\Mcp\Modules\Files\Creation\FileCreation;

/** Runs the operation that creates a file from inline text inside the actor view. */
final class CreateFileAction {
	/**
	 * @param FilesCallContext $context actor-root admission and node-access mapping
	 * @param ?FileCreation $creation optional inline and upload file creation service
	 */
	public function __construct(
		private FilesCallContext $context,
		private ?FileCreation $creation = null,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged tool result
	 */
	public function handle(array $arguments, string $userId): array {
		$confirmed = (bool)($arguments['confirm_shared'] ?? false);
		return $this->context->run($userId, fn (Folder $root): array => ToolResult::json((new CreateFileHandler(($this->creation ?? throw new \LogicException('FileCreation is not wired'))))->execute($root, $userId, $arguments['path'], $arguments['content'], $confirmed)));
	}
}
