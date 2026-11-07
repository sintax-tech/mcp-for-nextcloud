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

/** Publishes files_edit and delegates to the component that replaces file content through the guarded edit handler. */
final class EditFile implements WriteTool {
	/**
	 * @param \OCA\Mcp\Modules\Files\Editing\EditFileAction $handler executor that replaces file content through the guarded edit handler
	 * @param \OCA\Mcp\Modules\Files\Editing\EditFilePreview $plans read-only planner for this write operation
	 */
	public function __construct(private \OCA\Mcp\Modules\Files\Editing\EditFileAction $handler, private \OCA\Mcp\Modules\Files\Editing\EditFilePreview $plans) {
	}

	/** @return ToolDefinition unchanged static definition */
	public static function definition(): ToolDefinition {
		$path = ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::path()];
		$etag = ['type' => 'string', 'description' => FilesMessages::etag()];
		$confirmShared = ['type' => 'boolean', 'description' => FilesMessages::confirmShared()];
		return FilesSchema::definition(['name' => 'files_edit', 'module' => 'files', 'operation' => 'edit',
				'description' => \OCA\Mcp\Modules\Files\Presentation\EditingMessages::editTool(),
				'inputSchema' => FilesSchema::schema([
					'path' => $path,
					'content' => ['type' => 'string', 'description' => \OCA\Mcp\Modules\Files\Presentation\EditingMessages::content()],
					'etag' => $etag,
					'confirm_shared' => $confirmShared,
				], ['path', 'content'])]);
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
