<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Tools;

use OCA\Mcp\Modules\Talk\Messaging\BatchPlan;
use OCA\Mcp\Modules\Talk\Messaging\MessageText;

use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Talk\Execution\PreviewBoundary;
use OCA\Mcp\Modules\Talk\Execution\ResultBoundary;
use OCA\Mcp\Modules\Talk\Messaging\BatchSending;
use OCA\Mcp\Modules\Talk\Presentation\ToolMessages;

class SendBatch implements WriteTool
{
    public const NAME = 'talk_send_batch';
    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            module: 'talk',
            operation: 'reply',
            description: ToolMessages::TOOL_SEND_BATCH,
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'conversation_token' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'maxLength' => 30,
                    ],
                    'messages' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'maxItems' => BatchPlan::MAX_BATCH,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'message' => [
                                    'type' => 'string',
                                    'minLength' => 1,
                                    'maxLength' => MessageText::MAX_MESSAGE_LENGTH,
                                ],
                                'reply_to' => [
                                    'type' => 'integer',
                                    'minimum' => 1,
                                ],
                            ],
                            'required' => ['message'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['conversation_token', 'messages'],
                'additionalProperties' => false,
            ],
            app: 'spreed',
        );
    }
    public function __construct(
        private BatchSending $operation,
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
