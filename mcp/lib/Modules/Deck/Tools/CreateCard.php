<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Tools;

use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Deck\Cards\CardCreation;
use OCA\Mcp\Modules\Deck\Cards\CreateCardPlan;
use OCA\Mcp\Modules\Deck\Execution\ResultBoundary;
use OCA\Mcp\Modules\Deck\Execution\PreviewBoundary;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Deck\Presentation\ToolMessages;
use OCA\Mcp\Modules\Deck\Presentation\DeckSchema;
use OCA\Mcp\Mcp\Tools\ToolContext;

class CreateCard implements WriteTool {
    public const TOOL = 'deck_create_card';
    public function __construct(
        private CardCreation $operation,
        private CreateCardPlan $plan,
        private ResultBoundary $results,
        private PreviewBoundary $previews,
    ) {
    }

    public static function definition(): ToolDefinition {
        $d = [
            'name' => self::TOOL,
            'description' => ToolMessages::TOOL_CREATE_CARD_DESCRIPTION . ToolMessages::CONFIRM_SHARED_DESCRIPTION,
            'inputSchema' => DeckSchema::schema(
                [
                    'stackId' => DeckSchema::integer(ToolMessages::PARAM_STACK_ID),
                    'title' => DeckSchema::string(ToolMessages::PARAM_TITLE, 1, 255),
                    'description' => DeckSchema::string(ToolMessages::PARAM_DESCRIPTION, 0, null, ''),
                    'duedate' => DeckSchema::nullableString(ToolMessages::PARAM_DUEDATE, null),
                    'assignees' => DeckSchema::accountList(ToolMessages::PARAM_ASSIGNEES),
                    'confirm_shared' => DeckSchema::confirmShared(),
                ],
                ['stackId', 'title'],
            ),
            'module' => 'deck',
            'operation' => 'create',
            'app' => 'deck',
        ];
        return new ToolDefinition($d['name'], $d['module'], $d['operation'], $d['description'], $d['inputSchema'], $d['app']);
    }

    public function execute(array $arguments, ToolContext $context): array {
        return $this->results->run(self::TOOL, 'deck_list_cards', fn() => $this->operation->execute($arguments, $context->userId));
    }

    public function preview(array $arguments, ToolContext $context): array {
        return $this->previews->run(self::TOOL, $context->userId, fn() => $this->plan->build($arguments, $context->userId));
    }
}
