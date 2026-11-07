<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tools;

use OCA\Mcp\Modules\Files\Presentation\ImageMessages;
use OCA\Mcp\Modules\Files\Images\ImageTools;
use OCA\Mcp\Modules\Files\Presentation\FilesSchema;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;

/** Publishes files_image_search and delegates to the component that searches image candidates through the image service. */
final class SearchImages implements Tool {
	/**
	 * @param \OCA\Mcp\Modules\Files\Reading\SearchImagesHandler $handler executor that searches image candidates through the image service
	 */
	public function __construct(private \OCA\Mcp\Modules\Files\Reading\SearchImagesHandler $handler) {
	}

	/** @return ToolDefinition unchanged static definition */
	public static function definition(): ToolDefinition {
		return FilesSchema::definition(['name' => 'files_image_search', 'module' => 'files', 'operation' => 'read',
				'description' => ImageMessages::imageSearchTool(),
				'inputSchema' => FilesSchema::schema([
					'query' => ['type' => 'string', 'description' => ImageMessages::imageQuery()],
					'folder' => ['type' => 'string', 'minLength' => 1, 'description' => ImageMessages::imageFolder()],
					'modified_after' => ['type' => 'string', 'minLength' => 1, 'description' => ImageMessages::imageModifiedAfter()],
					'modified_before' => ['type' => 'string', 'minLength' => 1, 'description' => ImageMessages::imageModifiedBefore()],
					'tag' => ['type' => 'string', 'minLength' => 1, 'description' => ImageMessages::imageTag()],
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
