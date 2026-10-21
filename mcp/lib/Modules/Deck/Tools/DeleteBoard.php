<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Tools;

use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Deck\Structure\BoardDeletion;
use OCA\Mcp\Modules\Deck\Structure\DeleteBoardPlan;
use OCA\Mcp\Modules\Deck\Execution\ResultBoundary;
use OCA\Mcp\Modules\Deck\Execution\PreviewBoundary;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Deck\Presentation\ToolMessages;
use OCA\Mcp\Modules\Deck\Presentation\DeckSchema;
use OCA\Mcp\Mcp\Tools\ToolContext;

class DeleteBoard implements WriteTool {
    public const TOOL = 'deck_delete_board';
    public function __construct(
        private BoardDeletion $operation,
        private DeleteBoardPlan $plan,
        private ResultBoundary $results,
        private PreviewBoundary $previews,
    ) {
    }

    public static function definition(): ToolDefinition {
        $d = [
            'name' => self::TOOL,
            // Only the owner deletes a board, so `confirm_shared` does not exist here: a board of somebody else is refused.
            'description' => ToolMessages::TOOL_DELETE_BOARD_DESCRIPTION,
            'inputSchema' => DeckSchema::schema(['boardId' => DeckSchema::integer(ToolMessages::PARAM_BOARD_ID)], ['boardId']),
            'module' => 'deck',
            'operation' => 'delete',
            'app' => 'deck',
        ];
        return new ToolDefinition($d['name'], $d['module'], $d['operation'], $d['description'], $d['inputSchema'], $d['app']);
    }

    public function execute(array $arguments, ToolContext $context): array {
        return $this->results->run(self::TOOL, 'deck_list_boards', fn() => $this->operation->execute($arguments, $context->userId));
    }

    public function preview(array $arguments, ToolContext $context): array {
        return $this->previews->run(self::TOOL, $context->userId, fn() => $this->plan->build($arguments, $context->userId));
    }
}
