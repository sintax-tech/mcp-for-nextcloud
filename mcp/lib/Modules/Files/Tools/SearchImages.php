<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tools;

use OCA\Mcp\Modules\Files\FilesMessages;
use OCA\Mcp\Modules\Files\Images\ImageTools;
use OCA\Mcp\Modules\Files\Presentation\FilesSchema;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;

/** Publishes the unchanged files_image_search tool. */
final class SearchImages implements Tool {
	/**
	 * @param \OCA\Mcp\Modules\Files\Reading\SearchImagesHandler $handler existing effect composition
	 */
	public function __construct(private \OCA\Mcp\Modules\Files\Reading\SearchImagesHandler $handler) {
	}

	/** @return ToolDefinition unchanged static definition */
	public static function definition(): ToolDefinition {
		return FilesSchema::definition(['name' => 'files_image_search', 'module' => 'files', 'operation' => 'read',
				'description' => FilesMessages::imageSearchTool(),
				'inputSchema' => FilesSchema::schema([
					'query' => ['type' => 'string', 'description' => FilesMessages::imageQuery()],
					'folder' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::imageFolder()],
					'modified_after' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::imageModifiedAfter()],
					'modified_before' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::imageModifiedBefore()],
					'tag' => ['type' => 'string', 'minLength' => 1, 'description' => FilesMessages::imageTag()],
					'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ImageTools::SEARCH_MAX_LIMIT,
						'default' => ImageTools::SEARCH_DEFAULT_LIMIT],
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
