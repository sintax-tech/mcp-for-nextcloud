<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Tools;

use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Deck\Cards\CardDeletion;
use OCA\Mcp\Modules\Deck\Cards\DeleteCardPlan;
use OCA\Mcp\Modules\Deck\Execution\ResultBoundary;
use OCA\Mcp\Modules\Deck\Execution\PreviewBoundary;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Deck\Presentation\ToolMessages;
use OCA\Mcp\Modules\Deck\Presentation\DeckSchema;
use OCA\Mcp\Mcp\Tools\ToolContext;

class DeleteCard implements WriteTool {
    public const TOOL = 'deck_delete_card';
    public function __construct(
        private CardDeletion $operation,
        private DeleteCardPlan $plan,
        private ResultBoundary $results,
        private PreviewBoundary $previews,
    ) {
    }

    public static function definition(): ToolDefinition {
        $d = [
            'name' => self::TOOL,
            'description' => ToolMessages::TOOL_DELETE_CARD_DESCRIPTION . ToolMessages::CONFIRM_SHARED_DESCRIPTION,
            // `confirm` is not declared here: the registry publishes it on every write tool, so the
            // same wording and the same rule apply to all of them.
            'inputSchema' => DeckSchema::schema(
                [
                    'cardId' => DeckSchema::integer(ToolMessages::PARAM_CARD_ID),
                    'confirm_shared' => DeckSchema::confirmShared(),
                ],
                ['cardId'],
            ),
            'module' => 'deck',
            'operation' => 'delete',
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
