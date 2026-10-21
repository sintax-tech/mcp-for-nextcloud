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
use OCA\Mcp\Modules\Files\Sharing\ShareAccess;
use OCA\Mcp\Modules\Files\Sharing\ShareLister;
use OCA\Mcp\Modules\Files\Sharing\SharingMessages;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\Files\IRootFolder;

final class ListShares implements Tool {
    public function __construct(
        private IRootFolder $rootFolder,
        private ShareLister $lister,
    ) {}

    public static function definition(): ToolDefinition {
        return new ToolDefinition(
            name: 'files_list_shares',
            module: 'files',
            operation: 'read',
            description: SharingMessages::listSharesTool(),
            inputSchema: self::schema([
                    'path' => ['type' => 'string', 'minLength' => 1, 'description' => SharingMessages::listSharesPath()],
                    'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => ShareAccess::MAX_OFFSET, 'default' => 0,
                        'description' => SharingMessages::listSharesOffset()],
                ]),
        );
    }

    public function execute(array $arguments, ToolContext $context): array {
        $userId = $context->userId;
        $root = $this->rootFolder->getUserFolder($userId);
        return NodeAccess::run(fn (): array => ToolResult::json(($arguments['path'] ?? null) === null
            ? $this->lister->mine($root, $userId, (int)$arguments['offset'])
            : $this->lister->forPath($root, $userId, $arguments['path'])));
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
