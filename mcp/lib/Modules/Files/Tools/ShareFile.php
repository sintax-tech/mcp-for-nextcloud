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
use OCA\Mcp\Modules\Files\Reading\ReadingMessages;
use OCA\Mcp\Modules\Files\Sharing\SharePermission;
use OCA\Mcp\Modules\Files\Sharing\ShareWriter;
use OCA\Mcp\Modules\Files\Sharing\SharingMessages;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\Files\IRootFolder;

final class ShareFile implements WriteTool {
    public function __construct(
        private IRootFolder $rootFolder,
        private ShareWriter $writer,
    ) {}

    public static function definition(): ToolDefinition {
        $path = ['type' => 'string', 'minLength' => 1, 'description' => ReadingMessages::path()];
        return new ToolDefinition(
            name: 'files_share',
            module: 'files',
            operation: 'share',
            grantAnyOf: ['share', 'link'],
            destructiveHint: true,
            description: SharingMessages::shareTool(),
            inputSchema: self::schema([
                    'path' => $path,
                    'with' => ['type' => 'string', 'minLength' => 1, 'description' => SharingMessages::shareWithParam()],
                    'permission' => ['type' => 'string', 'enum' => SharePermission::LEVELS, 'default' => SharePermission::VIEW,
                        'description' => SharingMessages::sharePermissionParam()],
                    'expires' => ['type' => 'string', 'minLength' => 1, 'description' => SharingMessages::shareExpiresParam()],
                    'note' => ['type' => 'string', 'maxLength' => ShareWriter::NOTE_MAX, 'description' => SharingMessages::shareNoteParam()],
                    'password' => ['type' => 'boolean', 'description' => SharingMessages::sharePasswordParam()],
                    PlanState::ARGUMENT => PlanState::property(),
                ], ['path', 'with']),
        );
    }

    public function execute(array $arguments, ToolContext $context): array {
        $userId = $context->userId;
        $root = $this->rootFolder->getUserFolder($userId);
        return NodeAccess::run(function () use ($root, $userId, $arguments): array {
            $result = $this->writer->apply($root, $userId, $arguments);
            return ToolResult::structured(ToolResult::json($result)['content'][0]['text'], $result);
        });
    }

    public function preview(array $arguments, ToolContext $context): array {
        $userId = $context->userId;
        $root = $this->rootFolder->getUserFolder($userId);
        return NodeAccess::run(fn (): array => $this->writer->plan($root, $userId, $arguments));
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
