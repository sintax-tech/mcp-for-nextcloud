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
use OCA\Mcp\Modules\Files\Reading\ReadingMessages;
use OCA\Mcp\Modules\Files\Reading\TreeReader;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\Files\IRootFolder;

final class ReadTree implements Tool {
    public function __construct(
        private IRootFolder $rootFolder,
        private TreeReader $reader,
    ) {}

    public static function definition(): ToolDefinition {
        return new ToolDefinition(
            name: 'files_tree',
            module: 'files',
            operation: 'read',
            description: ReadingMessages::treeTool(),
            inputSchema: self::schema([
                    'path' => ['type' => 'string', 'default' => '/', 'description' => ReadingMessages::path()],
                    'depth' => ['type' => 'integer', 'minimum' => 1, 'maximum' => TreeReader::MAX_DEPTH, 'default' => TreeReader::MAX_DEPTH],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => TreeReader::MAX_ENTRIES, 'default' => TreeReader::MAX_ENTRIES],
                ]),
        );
    }

    public function execute(array $arguments, ToolContext $context): array {
        $userId = $context->userId;
        $root = $this->rootFolder->getUserFolder($userId);
        return NodeAccess::run(fn (): array => ToolResult::json($this->reader->tree($root, $userId, $arguments['path'], $arguments['depth'], $arguments['limit'])));
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
