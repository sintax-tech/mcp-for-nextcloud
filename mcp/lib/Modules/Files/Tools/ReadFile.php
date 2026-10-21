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
use OCA\Mcp\Modules\Files\Reading\FileReader;
use OCA\Mcp\Modules\Files\Reading\ReadingMessages;

final class ReadFile implements Tool {
    public function __construct(private FileReader $reader) {}

    public static function definition(): ToolDefinition {
        return new ToolDefinition(
            name: 'files_read',
            module: 'files',
            operation: 'read',
            description: ReadingMessages::readTool(),
            inputSchema: [
            'type' => 'object',
            'properties' => ['path' => ['type' => 'string', 'minLength' => 1, 'description' => ReadingMessages::path()]],
            'additionalProperties' => false,
            'required' => ['path'],
        ],
        );
    }

    public function execute(array $arguments, ToolContext $context): array {
        return $this->reader->readText($context->userId, $arguments['path'])->toToolResult();
    }
}
