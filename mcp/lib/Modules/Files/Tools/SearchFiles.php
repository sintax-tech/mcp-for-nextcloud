<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tools;

use OCA\Mcp\Modules\Files\Presentation\ReadingMessages;
use OCA\Mcp\Modules\Files\Presentation\FilesSchema;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;

/** Publishes the unchanged files_search tool. */
final class SearchFiles implements Tool {
	/**
	 * @param \OCA\Mcp\Modules\Files\Reading\SearchFilesHandler $handler existing effect composition
	 */
	public function __construct(private \OCA\Mcp\Modules\Files\Reading\SearchFilesHandler $handler) {
	}

	/** @return ToolDefinition unchanged static definition */
	public static function definition(): ToolDefinition {
		return FilesSchema::definition(['name' => 'files_search', 'module' => 'files', 'operation' => 'read',
				'description' => ReadingMessages::searchTool(),
				'inputSchema' => FilesSchema::schema([
					'query' => ['type' => 'string', 'minLength' => 1, 'description' => ReadingMessages::searchQuery()],
					'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25, 'description' => ReadingMessages::searchLimit()],
					'mode' => ['type' => 'string', 'enum' => ['auto', 'name'], 'default' => 'auto', 'description' => ReadingMessages::searchMode()],
				], ['query'])]);
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
