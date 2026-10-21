<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tools;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Files\Images\ImageMessages;
use OCA\Mcp\Modules\Files\Images\ImageSearch;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\Files\IRootFolder;

final class SearchImages implements Tool {
    public function __construct(
        private IRootFolder $rootFolder,
        private ImageSearch $images,
    ) {}

    public static function definition(): ToolDefinition {
        return new ToolDefinition(
            name: 'files_image_search',
            module: 'files',
            operation: 'read',
            description: ImageMessages::imageSearchTool(),
            inputSchema: self::schema([
                    'query' => ['type' => 'string', 'description' => ImageMessages::imageQuery()],
                    'folder' => ['type' => 'string', 'minLength' => 1, 'description' => ImageMessages::imageFolder()],
                    'modified_after' => ['type' => 'string', 'minLength' => 1, 'description' => ImageMessages::imageModifiedAfter()],
                    'modified_before' => ['type' => 'string', 'minLength' => 1, 'description' => ImageMessages::imageModifiedBefore()],
                    'tag' => ['type' => 'string', 'minLength' => 1, 'description' => ImageMessages::imageTag()],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ImageSearch::SEARCH_MAX_LIMIT,
                        'default' => ImageSearch::SEARCH_DEFAULT_LIMIT],
                ]),
        );
    }

    public function execute(array $arguments, ToolContext $context): array {
        $userId = $context->userId;
        $root = $this->rootFolder->getUserFolder($userId);
        return NodeAccess::run(fn (): array => ToolResult::json($this->images->search($root, $userId, $arguments)));
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string> $required
     * @return array<string, mixed>
     */
    private static function schema(array $properties, array $required = []): array {
        $schema = ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
        return $required === [] ? $schema : $schema + ['required' => $required];
    }
}
