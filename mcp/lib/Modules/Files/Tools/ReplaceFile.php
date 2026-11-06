<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tools;

use OCA\Mcp\Modules\Files\Presentation\FilesMessages;
use OCA\Mcp\Modules\Files\Presentation\FilesSchema;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Mcp\Tools\ToolContext;

/** Publishes the unchanged files_replace tool. */
final class ReplaceFile implements WriteTool {
	/**
	 * @param \OCA\Mcp\Modules\Files\Editing\ReplaceFileAction $handler existing effect composition
	 * @param \OCA\Mcp\Modules\Files\Editing\ReplaceFilePreview $plans distinct plan composition
	 */
	public function __construct(private \OCA\Mcp\Modules\Files\Editing\ReplaceFileAction $handler, private \OCA\Mcp\Modules\Files\Editing\ReplaceFilePreview $plans) {
	}

	/** @return ToolDefinition unchanged static definition */
	public static function definition(): ToolDefinition {
		$path = ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::path()];
		$etag = ['type' => 'string', 'description' => FilesMessages::etag()];
		$confirmShared = ['type' => 'boolean', 'description' => FilesMessages::confirmShared()];
		return FilesSchema::definition(['name' => 'files_replace', 'module' => 'files', 'operation' => 'edit',
				'description' => \OCA\Mcp\Modules\Files\Presentation\EditingMessages::replaceTool(),
				'inputSchema' => FilesSchema::schema([
					'path' => $path,
					'old' => ['type' => 'string', 'minLength' => 1, 'maxLength' => \OCA\Mcp\Platform\Text\UniqueSnippet::MAX_BYTES, 'description' => \OCA\Mcp\Modules\Files\Presentation\EditingMessages::oldSnippet()],
					'new' => ['type' => 'string', 'description' => \OCA\Mcp\Modules\Files\Presentation\EditingMessages::newSnippet()],
					'etag' => $etag,
					'confirm_shared' => $confirmShared,
				], ['path', 'old', 'new'])]);
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param ToolContext $context authenticated invocation
	 * @return array<string,mixed> unchanged result
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->handler->handle($arguments, $context->userId);
	}
	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param ToolContext $context authenticated invocation
	 * @return array<string,mixed> unchanged result
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return $this->plans->plan($arguments, $context->userId);
	}
}
