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
use OCA\Mcp\Modules\Files\Reading\ReadingMessages;
use OCA\Mcp\Modules\Files\Versions\VersionMessages;
use OCA\Mcp\Modules\Files\Versions\VersionReader;
use OCA\Mcp\Modules\Files\Versions\VersionRestorer;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCP\Files\IRootFolder;

final class RestoreVersion implements WriteTool {
    public function __construct(
        private IRootFolder $rootFolder,
        private VersionRestorer $restorer,
    ) {}

    public static function definition(): ToolDefinition {
        $path = ['type' => 'string', 'minLength' => 1, 'description' => ReadingMessages::path()];
        $confirmShared = ['type' => 'boolean', 'description' => EditingMessages::confirmShared()];
        $version = ['type' => 'string', 'minLength' => 1, 'description' => VersionMessages::version()];
        return new ToolDefinition(
            name: 'files_version_restore',
            module: 'files',
            operation: 'restore',
            app: VersionReader::APP,
            description: VersionMessages::versionRestoreTool(),
            inputSchema: self::schema([
                    'path' => $path,
                    'version' => $version,
                    'confirm_shared' => $confirmShared,
                ], ['path', 'version']),
        );
    }

    public function execute(array $arguments, ToolContext $context): array {
        $userId = $context->userId;
        $root = $this->rootFolder->getUserFolder($userId);
        return NodeAccess::run(fn (): array => $this->restorer->execute($root, $userId, $arguments['path'], $arguments['version'], (bool)($arguments['confirm_shared'] ?? false)));
    }

    public function preview(array $arguments, ToolContext $context): array {
        $userId = $context->userId;
        $root = $this->rootFolder->getUserFolder($userId);
        return NodeAccess::run(fn (): array => $this->restorer->preview($root, $userId, $arguments['path'], $arguments['version']));
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
