<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Tools;

use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Deck\Structure\StackCreation;
use OCA\Mcp\Modules\Deck\Structure\CreateStackPlan;
use OCA\Mcp\Modules\Deck\Execution\ResultBoundary;
use OCA\Mcp\Modules\Deck\Execution\PreviewBoundary;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Deck\Presentation\ToolMessages;
use OCA\Mcp\Modules\Deck\Presentation\DeckSchema;
use OCA\Mcp\Modules\Deck\Structure\BoardBlueprint;
use OCA\Mcp\Mcp\Tools\ToolContext;

class CreateStack implements WriteTool {
    public const TOOL = 'deck_create_stack';
    public function __construct(
        private StackCreation $operation,
        private CreateStackPlan $plan,
        private ResultBoundary $results,
        private PreviewBoundary $previews,
    ) {
    }

    public static function definition(): ToolDefinition {
        $d = [
            'name' => self::TOOL,
            'description' => ToolMessages::TOOL_CREATE_STACK_DESCRIPTION . ToolMessages::CONFIRM_SHARED_DESCRIPTION,
            'inputSchema' => DeckSchema::schema(
                [
                    'boardId' => DeckSchema::integer(ToolMessages::PARAM_BOARD_ID),
                    'title' => DeckSchema::string(ToolMessages::PARAM_STACK_TITLE, 1, BoardBlueprint::MAX_TITLE_LENGTH),
                    'order' => DeckSchema::integer(ToolMessages::PARAM_STACK_ORDER, 0, 99999),
                    'confirm_shared' => DeckSchema::confirmShared(),
                ],
                ['boardId', 'title'],
            ),
            'module' => 'deck',
            'operation' => 'create',
            'app' => 'deck',
        ];
        return new ToolDefinition($d['name'], $d['module'], $d['operation'], $d['description'], $d['inputSchema'], $d['app']);
    }

    public function execute(array $arguments, ToolContext $context): array {
        return $this->results->run(self::TOOL, 'deck_list_stacks', fn() => $this->operation->execute($arguments, $context->userId));
    }

    public function preview(array $arguments, ToolContext $context): array {
        return $this->previews->run(self::TOOL, $context->userId, fn() => $this->plan->build($arguments, $context->userId));
    }
}
