<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Tools;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Talk\Execution\ResultBoundary;
use OCA\Mcp\Modules\Talk\Presentation\ToolMessages;

class ListConversations implements Tool
{
    public const NAME = 'talk_list_conversations';
    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            module: 'talk',
            operation: 'read',
            description: ToolMessages::TOOL_LIST_CONVERSATIONS,
            inputSchema: [
                'type' => 'object',
                'properties' => new \stdClass(),
                'additionalProperties' => false,
            ],
            app: 'spreed',
        );
    }
    public function __construct(private \Closure $read, private ResultBoundary $results) {}
    public function execute(array $arguments, ToolContext $context): array
    {
        return $this->results->run(self::NAME, fn(): array => ['conversations' => ($this->read)($context->userId)]);
    }
}
