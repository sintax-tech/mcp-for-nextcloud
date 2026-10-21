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
use OCA\Mcp\Modules\Files\Search\FileSearch;
use OCA\Mcp\Modules\Files\Search\SearchMessages;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\Files\IRootFolder;

final class SearchFiles implements Tool {
    public function __construct(
        private IRootFolder $rootFolder,
        private FileSearch $reader,
    ) {}

    public static function definition(): ToolDefinition {
        return new ToolDefinition(
            name: 'files_search',
            module: 'files',
            operation: 'read',
            description: SearchMessages::searchTool(),
            inputSchema: self::schema([
                    'query' => ['type' => 'string', 'minLength' => 1, 'description' => SearchMessages::searchQuery()],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25, 'description' => SearchMessages::searchLimit()],
                    'mode' => ['type' => 'string', 'enum' => ['auto', 'name'], 'default' => 'auto', 'description' => SearchMessages::searchMode()],
                ], ['query']),
        );
    }

    public function execute(array $arguments, ToolContext $context): array {
        $userId = $context->userId;
        $root = $this->rootFolder->getUserFolder($userId);
        return NodeAccess::run(fn (): array => ToolResult::json($this->reader->search($root, $userId, $arguments['query'], (int)$arguments['limit'], (string)($arguments['mode'] ?? 'auto'))));
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
