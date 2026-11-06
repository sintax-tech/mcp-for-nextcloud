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

/** Publishes files_upload and delegates to the component that creates a file from an upload link. */
final class UploadFile implements WriteTool {
	/**
	 * @param \OCA\Mcp\Modules\Files\Creation\UploadFileAction $handler executor that creates a file from an upload link
	 * @param \OCA\Mcp\Modules\Files\Creation\UploadFilePreview $plans read-only planner for this write operation
	 */
	public function __construct(private \OCA\Mcp\Modules\Files\Creation\UploadFileAction $handler, private \OCA\Mcp\Modules\Files\Creation\UploadFilePreview $plans) {
	}

	/** @return ToolDefinition unchanged static definition */
	public static function definition(): ToolDefinition {
		$confirmShared = ['type' => 'boolean', 'description' => FilesMessages::confirmShared()];
		return FilesSchema::definition(['name' => 'files_upload', 'module' => 'files', 'operation' => 'create',
				'description' => \OCA\Mcp\Modules\Files\Presentation\CreationMessages::uploadTool(),
				'inputSchema' => FilesSchema::schema([
					'path' => ['type' => 'string', 'minLength' => 1, 'description' => \OCA\Mcp\Modules\Files\Presentation\CreationMessages::newFilePath()],
					'size' => ['type' => 'integer', 'minimum' => 0, 'description' => \OCA\Mcp\Modules\Files\Presentation\CreationMessages::uploadSize()],
					'confirm_shared' => $confirmShared,
				], ['path'])]);
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
