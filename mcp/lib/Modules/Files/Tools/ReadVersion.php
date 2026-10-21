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
use OCA\Mcp\Modules\Files\Versions\VersionMessages;
use OCA\Mcp\Modules\Files\Versions\VersionReader;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\Files\IRootFolder;

final class ReadVersion implements Tool {
    public function __construct(
        private IRootFolder $rootFolder,
        private VersionReader $versions,
        private FilesAccessGuard $visibilityGuard,
    ) {}

    public static function definition(): ToolDefinition {
        $path = ['type' => 'string', 'minLength' => 1, 'description' => ReadingMessages::path()];
        $version = ['type' => 'string', 'minLength' => 1, 'description' => VersionMessages::version()];
        return new ToolDefinition(
            name: 'files_version_read',
            module: 'files',
            operation: 'read',
            app: VersionReader::APP,
            description: VersionMessages::versionReadTool(),
            inputSchema: self::schema(['path' => $path, 'version' => $version], ['path', 'version']),
        );
    }

    public function execute(array $arguments, ToolContext $context): array {
        $userId = $context->userId;
        $root = $this->rootFolder->getUserFolder($userId);
        return NodeAccess::run(fn (): array => ToolResult::json($this->versions->read(NodeAccess::requireFile(NodeAccess::get($root, $arguments['path'], $this->visibilityGuard)), PathGuard::normalize($arguments['path']), $arguments['version'], $userId)));
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
