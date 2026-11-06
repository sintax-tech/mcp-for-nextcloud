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

/** Publishes the unchanged files_create tool. */
final class CreateFile implements WriteTool {
	/**
	 * @param \OCA\Mcp\Modules\Files\Creation\CreateFileAction $handler existing effect composition
	 * @param \OCA\Mcp\Modules\Files\Creation\CreateFilePreview $plans distinct plan composition
	 */
	public function __construct(private \OCA\Mcp\Modules\Files\Creation\CreateFileAction $handler, private \OCA\Mcp\Modules\Files\Creation\CreateFilePreview $plans) {
	}

	/** @return ToolDefinition unchanged static definition */
	public static function definition(): ToolDefinition {
		$confirmShared = ['type' => 'boolean', 'description' => FilesMessages::confirmShared()];
		return FilesSchema::definition(['name' => 'files_create', 'module' => 'files', 'operation' => 'create',
				'description' => \OCA\Mcp\Modules\Files\Presentation\CreationMessages::createTool(),
				'inputSchema' => FilesSchema::schema([
					'path' => ['type' => 'string', 'minLength' => 1, 'description' => \OCA\Mcp\Modules\Files\Presentation\CreationMessages::newFilePath()],
					'content' => ['type' => 'string', 'description' => \OCA\Mcp\Modules\Files\Presentation\CreationMessages::createContent()],
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
