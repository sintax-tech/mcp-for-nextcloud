<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Tools;

use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Deck\Cards\CardEditing;
use OCA\Mcp\Modules\Deck\Cards\EditCardPlan;
use OCA\Mcp\Modules\Deck\Execution\ResultBoundary;
use OCA\Mcp\Modules\Deck\Execution\PreviewBoundary;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Deck\Presentation\ToolMessages;
use OCA\Mcp\Modules\Deck\Presentation\DeckSchema;
use OCA\Mcp\Mcp\Tools\ToolContext;

class EditCard implements WriteTool {
    public const TOOL = 'deck_edit_card';
    public function __construct(
        private CardEditing $operation,
        private EditCardPlan $plan,
        private ResultBoundary $results,
        private PreviewBoundary $previews,
    ) {
    }

    public static function definition(): ToolDefinition {
        $d = [
            'name' => self::TOOL,
            'description' => ToolMessages::TOOL_EDIT_CARD_DESCRIPTION . ToolMessages::CONFIRM_SHARED_DESCRIPTION,
            // `duedate` has no default here on purpose: absent means "keep the current date",
            // while an explicit null clears it.
            'inputSchema' => DeckSchema::schema(
                [
                    'cardId' => DeckSchema::integer(ToolMessages::PARAM_CARD_ID),
                    'title' => DeckSchema::string(ToolMessages::PARAM_TITLE, 1, 255),
                    'description' => DeckSchema::string(ToolMessages::PARAM_DESCRIPTION, 0),
                    'duedate' => DeckSchema::nullableString(ToolMessages::PARAM_DUEDATE),
                    'assign' => DeckSchema::accountList(ToolMessages::PARAM_ASSIGN),
                    'unassign' => DeckSchema::accountList(ToolMessages::PARAM_UNASSIGN),
                    'lastModified' => DeckSchema::integer(ToolMessages::PARAM_LAST_MODIFIED, 0),
                    'confirm_shared' => DeckSchema::confirmShared(),
                ],
                ['cardId'],
            ),
            'module' => 'deck',
            'operation' => 'edit',
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
