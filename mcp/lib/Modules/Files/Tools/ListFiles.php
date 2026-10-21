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
use OCA\Mcp\Modules\Files\Reading\DirectoryReader;
use OCA\Mcp\Modules\Files\Reading\ReadingMessages;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\Files\IRootFolder;

final class ListFiles implements Tool {
    public function __construct(
        private IRootFolder $rootFolder,
        private DirectoryReader $reader,
    ) {}

    public static function definition(): ToolDefinition {
        return new ToolDefinition(
            name: 'files_list',
            module: 'files',
            operation: 'read',
            description: ReadingMessages::listTool(),
            inputSchema: self::schema(['path' => ['type' => 'string', 'default' => '/', 'description' => ReadingMessages::path()]]),
        );
    }

    public function execute(array $arguments, ToolContext $context): array {
        $userId = $context->userId;
        $root = $this->rootFolder->getUserFolder($userId);
        return NodeAccess::run(fn (): array => ToolResult::json($this->reader->list($root, $userId, $arguments['path'])));
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
