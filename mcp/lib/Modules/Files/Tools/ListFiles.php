<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tools;

use OCA\Mcp\Modules\Files\FilesMessages;
use OCA\Mcp\Modules\Files\Presentation\FilesSchema;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;

/** Publishes the unchanged files_list tool. */
final class ListFiles implements Tool {
	/**
	 * @param \OCA\Mcp\Modules\Files\Reading\ListFilesHandler $handler existing effect composition
	 */
	public function __construct(private \OCA\Mcp\Modules\Files\Reading\ListFilesHandler $handler) {
	}

	/** @return ToolDefinition unchanged static definition */
	public static function definition(): ToolDefinition {
		return FilesSchema::definition(['name' => 'files_list', 'module' => 'files', 'operation' => 'read',
				'description' => FilesMessages::listTool(),
				'inputSchema' => FilesSchema::schema(['path' => ['type' => 'string', 'default' => '/', 'description' => FilesMessages::path()]])]);
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param ToolContext $context authenticated invocation
	 * @return array<string,mixed> unchanged result
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->handler->handle($arguments, $context->userId);
	}
}
