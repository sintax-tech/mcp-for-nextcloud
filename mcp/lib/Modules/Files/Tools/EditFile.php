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

/** Publishes the unchanged files_edit tool. */
final class EditFile implements WriteTool {
	/**
	 * @param \OCA\Mcp\Modules\Files\Editing\EditFileAction $handler existing effect composition
	 * @param \OCA\Mcp\Modules\Files\Editing\EditFilePreview $plans distinct plan composition
	 */
	public function __construct(private \OCA\Mcp\Modules\Files\Editing\EditFileAction $handler, private \OCA\Mcp\Modules\Files\Editing\EditFilePreview $plans) {
	}

	/** @return ToolDefinition unchanged static definition */
	public static function definition(): ToolDefinition {
		$path = ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::path()];
		$etag = ['type' => 'string', 'description' => FilesMessages::etag()];
		$confirmShared = ['type' => 'boolean', 'description' => FilesMessages::confirmShared()];
		return FilesSchema::definition(['name' => 'files_edit', 'module' => 'files', 'operation' => 'edit',
				'description' => FilesMessages::editTool(),
				'inputSchema' => FilesSchema::schema([
					'path' => $path,
					'content' => ['type' => 'string', 'description' => FilesMessages::content()],
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
