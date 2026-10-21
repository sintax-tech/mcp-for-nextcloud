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
use OCA\Mcp\Modules\Files\Sharing\ShareRemover;
use OCA\Mcp\Modules\Files\Sharing\SharingMessages;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\Files\IRootFolder;

final class UnshareFile implements WriteTool {
    public function __construct(
        private IRootFolder $rootFolder,
        private ShareRemover $remover,
    ) {}

    public static function definition(): ToolDefinition {
        return new ToolDefinition(
            name: 'files_unshare',
            module: 'files',
            operation: 'share',
            grantAnyOf: ['share', 'link'],
            destructiveHint: true,
            description: SharingMessages::unshareTool(),
            inputSchema: self::schema([
                    'shareId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128, 'description' => SharingMessages::unshareIdParam()],
                    'path' => ['type' => 'string', 'minLength' => 1, 'description' => SharingMessages::unsharePathParam()],
                    'with' => ['type' => 'string', 'minLength' => 1, 'description' => SharingMessages::unshareWithParam()],
                    PlanState::ARGUMENT => PlanState::property(),
                ]),
        );
    }

    public function execute(array $arguments, ToolContext $context): array {
        $userId = $context->userId;
        $root = $this->rootFolder->getUserFolder($userId);
        return NodeAccess::run(fn (): array => ToolResult::json($this->remover->apply($root, $userId, $arguments)));
    }

    public function preview(array $arguments, ToolContext $context): array {
        $userId = $context->userId;
        $root = $this->rootFolder->getUserFolder($userId);
        return NodeAccess::run(fn (): array => $this->remover->plan($root, $userId, $arguments));
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
