<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Tools;

use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Deck\Cards\CardMovement;
use OCA\Mcp\Modules\Deck\Cards\MoveCardPlan;
use OCA\Mcp\Modules\Deck\Execution\ResultBoundary;
use OCA\Mcp\Modules\Deck\Execution\PreviewBoundary;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Deck\Presentation\ToolMessages;
use OCA\Mcp\Modules\Deck\Presentation\DeckSchema;
use OCA\Mcp\Mcp\Tools\ToolContext;

class MoveCard implements WriteTool {
    public const TOOL = 'deck_move_card';
    public function __construct(
        private CardMovement $operation,
        private MoveCardPlan $plan,
        private ResultBoundary $results,
        private PreviewBoundary $previews,
    ) {
    }

    public static function definition(): ToolDefinition {
        $d = [
            'name' => self::TOOL,
            'description' => ToolMessages::TOOL_MOVE_CARD_DESCRIPTION . ToolMessages::CONFIRM_SHARED_DESCRIPTION,
            'inputSchema' => DeckSchema::schema(
                [
                    'cardId' => DeckSchema::integer(ToolMessages::PARAM_CARD_ID),
                    'stackId' => DeckSchema::integer(ToolMessages::PARAM_STACK_ID),
                    'order' => DeckSchema::integer(ToolMessages::PARAM_ORDER, 0, 99999),
                    'confirm_shared' => DeckSchema::confirmShared(),
                ],
                ['cardId', 'stackId'],
            ),
            'module' => 'deck',
            'operation' => 'move',
            'app' => 'deck',
        ];
        return new ToolDefinition($d['name'], $d['module'], $d['operation'], $d['description'], $d['inputSchema'], $d['app']);
    }

    public function execute(array $arguments, ToolContext $context): array {
        return $this->results->run(self::TOOL, 'deck_read_card', fn() => $this->operation->execute($arguments, $context->userId));
    }

    public function preview(array $arguments, ToolContext $context): array {
        return $this->previews->run(self::TOOL, $context->userId, fn() => $this->plan->build($arguments, $context->userId));
    }
}
