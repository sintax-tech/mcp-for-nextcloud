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
use OCA\Mcp\Modules\Files\Images\ImageViewer;
use OCA\Mcp\Modules\Files\Reading\ReadingMessages;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCP\Files\IRootFolder;

final class ViewImage implements Tool {
    public function __construct(
        private IRootFolder $rootFolder,
        private ImageViewer $images,
    ) {}

    public static function definition(): ToolDefinition {
        $path = ['type' => 'string', 'minLength' => 1, 'description' => ReadingMessages::path()];
        return new ToolDefinition(
            name: 'files_image_view',
            module: 'files',
            operation: 'read',
            description: ImageMessages::imageViewTool(),
            inputSchema: self::schema([
                    'path' => $path,
                    'max_size' => ['type' => 'integer', 'minimum' => ImageViewer::MIN_MAX_SIZE, 'maximum' => ImageViewer::MAX_MAX_SIZE,
                        'default' => ImageViewer::DEFAULT_MAX_SIZE, 'description' => ImageMessages::imageMaxSize()],
                ], ['path']),
        );
    }

    public function execute(array $arguments, ToolContext $context): array {
        $userId = $context->userId;
        $root = $this->rootFolder->getUserFolder($userId);
        return NodeAccess::run(fn (): array => $this->images->view($root, $userId, $arguments['path'], $arguments['max_size']));
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
