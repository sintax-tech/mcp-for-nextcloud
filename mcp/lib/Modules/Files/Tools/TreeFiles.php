<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tools;

use OCA\Mcp\Modules\Files\Reorganization;
use OCA\Mcp\Modules\Files\Presentation\ReadingMessages;
use OCA\Mcp\Modules\Files\Presentation\FilesSchema;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;

/** Publishes files_tree and delegates to the component that reads a bounded directory tree. */
final class TreeFiles implements Tool {
	/**
	 * @param \OCA\Mcp\Modules\Files\Reading\TreeFilesHandler $handler executor that reads a bounded directory tree
	 */
	public function __construct(private \OCA\Mcp\Modules\Files\Reading\TreeFilesHandler $handler) {
	}

	/** @return ToolDefinition unchanged static definition */
	public static function definition(): ToolDefinition {
		return FilesSchema::definition(['name' => 'files_tree', 'module' => 'files', 'operation' => 'read',
				'description' => ReadingMessages::treeTool(),
				'inputSchema' => FilesSchema::schema([
					'path' => ['type' => 'string', 'default' => '/', 'description' => \OCA\Mcp\Modules\Files\Presentation\FilesMessages::path()],
					'depth' => ['type' => 'integer', 'minimum' => 1, 'maximum' => Reorganization::MAX_DEPTH, 'default' => Reorganization::MAX_DEPTH],
					'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => Reorganization::MAX_ENTRIES, 'default' => Reorganization::MAX_ENTRIES],
				])]);
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
