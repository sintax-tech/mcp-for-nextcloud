<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Tools;

use OCA\Mcp\Modules\Talk\Messaging\MessageText;
use OCA\Mcp\Modules\Talk\References\ReferenceLinker;

use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Talk\Execution\PreviewBoundary;
use OCA\Mcp\Modules\Talk\Execution\ResultBoundary;
use OCA\Mcp\Modules\Talk\Messaging\Reply as ReplyOperation;
use OCA\Mcp\Modules\Talk\Presentation\ToolMessages;

class Reply implements WriteTool
{
    public const NAME = 'talk_reply';
    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            module: 'talk',
            operation: 'reply',
            description: ToolMessages::TOOL_REPLY,
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'conversation_token' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'maxLength' => 30,
                    ],
                    'message' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'maxLength' => MessageText::MAX_MESSAGE_LENGTH,
                    ],
                    'reply_to' => ['type' => 'integer', 'minimum' => 1],
                    'reference' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => [
                                'type' => 'string',
                                'enum' => ['deck_card', 'calendar_event'],
                            ],
                            'card_id' => ['type' => 'integer', 'minimum' => 1],
                            'board_id' => ['type' => 'integer', 'minimum' => 1],
                            'calendar' => [
                                'type' => 'string',
                                'minLength' => 1,
                                'maxLength' => ReferenceLinker::MAX_CALENDAR_LENGTH,
                            ],
                            'uid' => [
                                'type' => 'string',
                                'minLength' => 1,
                                'maxLength' => ReferenceLinker::MAX_UID_LENGTH,
                            ],
                        ],
                        'required' => ['type'],
                        'additionalProperties' => false,
                    ],
                ],
                'required' => ['conversation_token', 'message'],
                'additionalProperties' => false,
            ],
            app: 'spreed',
        );
    }
    public function __construct(
        private ReplyOperation $operation,
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
