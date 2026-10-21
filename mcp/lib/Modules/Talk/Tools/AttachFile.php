<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Tools;

use OCA\Mcp\Modules\Talk\Messaging\MessageText;

use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Talk\Attachments\FileAttachment;
use OCA\Mcp\Modules\Talk\Execution\PreviewBoundary;
use OCA\Mcp\Modules\Talk\Execution\ResultBoundary;
use OCA\Mcp\Modules\Talk\Presentation\ToolMessages;

class AttachFile implements WriteTool
{
    public const NAME = 'talk_attach_file';
    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            module: 'talk',
            operation: 'attach',
            description: ToolMessages::TOOL_ATTACH_FILE,
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'conversation_token' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'maxLength' => 30,
                    ],
                    'path' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'maxLength' => MessageText::MAX_MESSAGE_LENGTH,
                    ],
                    'message' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'maxLength' => MessageText::MAX_MESSAGE_LENGTH,
                    ],
                ],
                'required' => ['conversation_token', 'path'],
                'additionalProperties' => false,
            ],
            app: 'spreed',
        );
    }
    public function __construct(
        private FileAttachment $operation,
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
