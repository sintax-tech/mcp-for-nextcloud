<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Tools;

use OCA\Mcp\Modules\Talk\DirectMessaging\ContactResolver;
use OCA\Mcp\Modules\Talk\Groups\GroupCreation;

use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Talk\Execution\PreviewBoundary;
use OCA\Mcp\Modules\Talk\Execution\ResultBoundary;
use OCA\Mcp\Modules\Talk\Groups\GroupWorkflow;
use OCA\Mcp\Modules\Talk\Presentation\ToolMessages;

class CreateGroup implements WriteTool
{
    public const NAME = 'talk_create_group';
    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            module: 'talk',
            operation: 'create',
            description: ToolMessages::TOOL_CREATE_GROUP,
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'maxLength' => GroupCreation::MAX_NAME_LENGTH,
                    ],
                    'participants' => [
                        'type' => 'array',
                        'maxItems' => GroupCreation::MAX_PARTICIPANTS,
                        'items' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'maxLength' => ContactResolver::MAX_TARGET_LENGTH,
                        ],
                    ],
                ],
                'required' => ['name'],
                'additionalProperties' => false,
            ],
            app: 'spreed',
        );
    }
    public function __construct(
        private GroupWorkflow $operation,
        private ResultBoundary $results,
        private PreviewBoundary $previews,
    ) {}
    public function execute(array $arguments, ToolContext $context): array
    {
        return $this->results->run(self::NAME, fn(): array => $this->operation->execute($context->userId, $arguments));
    }
    public function preview(array $arguments, ToolContext $context): array
    {
        return $this->previews->run(self::NAME, fn(): array => $this->operation->preview($context->userId, $arguments));
    }
}
