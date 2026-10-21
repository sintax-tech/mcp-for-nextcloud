<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tools;

use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Files\Editing\EditingMessages;
use OCA\Mcp\Modules\Files\Editing\TextReplacer;
use OCA\Mcp\Modules\Files\Reading\ReadingMessages;

final class ReplaceText implements WriteTool {
    public function __construct(
        private TextReplacer $replacer,
    ) {}

    public static function definition(): ToolDefinition {
        $path = ['type' => 'string', 'minLength' => 1, 'description' => ReadingMessages::path()];
        $etag = ['type' => 'string', 'description' => EditingMessages::etag()];
        $confirmShared = ['type' => 'boolean', 'description' => EditingMessages::confirmShared()];
        return new ToolDefinition(
            name: 'files_replace',
            module: 'files',
            operation: 'edit',
            description: EditingMessages::replaceTool(),
            inputSchema: self::schema([
                    'path' => $path,
                    'old' => ['type' => 'string', 'minLength' => 1, 'description' => EditingMessages::oldSnippet()],
                    'new' => ['type' => 'string', 'description' => EditingMessages::newSnippet()],
                    'etag' => $etag,
                    'confirm_shared' => $confirmShared,
                ], ['path', 'old', 'new']),
        );
    }

    public function execute(array $arguments, ToolContext $context): array {
        $userId = $context->userId;
        return $this->replacer->execute($arguments, $userId);
    }

    public function preview(array $arguments, ToolContext $context): array {
        $userId = $context->userId;
        return $this->replacer->preview($arguments, $userId);
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
