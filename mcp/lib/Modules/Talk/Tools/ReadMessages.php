<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Tools;

use OCA\Mcp\Modules\Talk\Reading\ConversationReader;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Talk\Access\ConversationMessages;
use OCA\Mcp\Modules\Talk\Execution\ResultBoundary;
use OCA\Mcp\Modules\Talk\Presentation\ToolMessages;

class ReadMessages implements Tool
{
    public const NAME = 'talk_read_messages';
    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            module: 'talk',
            operation: 'read',
            description: ToolMessages::TOOL_READ_MESSAGES,
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'conversation_token' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'maxLength' => 30,
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => ConversationReader::MAX_MESSAGES,
                        'default' => ConversationReader::DEFAULT_MESSAGES,
                    ],
                ],
                'required' => ['conversation_token'],
                'additionalProperties' => false,
            ],
            app: 'spreed',
        );
    }
    public function __construct(private \Closure $read, private ResultBoundary $results) {}
    public function execute(array $arguments, ToolContext $context): array
    {
        return $this->results->run(
            self::NAME,
            fn(): array => [
                'messages' => ($this->read)(
                    $context->userId,
                    (string) ($arguments['conversation_token'] ??
                        throw new \InvalidArgumentException(ConversationMessages::invalidToken())),
                    (int) ($arguments['limit'] ?? ConversationReader::DEFAULT_MESSAGES),
                ),
            ],
        );
    }
}
