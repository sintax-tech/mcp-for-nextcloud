<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Tools;

use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Deck\Structure\BoardCreation;
use OCA\Mcp\Modules\Deck\Structure\CreateBoardPlan;
use OCA\Mcp\Modules\Deck\Execution\ResultBoundary;
use OCA\Mcp\Modules\Deck\Execution\PreviewBoundary;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Deck\Presentation\ToolMessages;
use OCA\Mcp\Modules\Deck\Presentation\DeckSchema;
use OCA\Mcp\Modules\Deck\Structure\BoardBlueprint;
use OCA\Mcp\Mcp\Tools\ToolContext;

class CreateBoard implements WriteTool {
    public const TOOL = 'deck_create_board';
    public function __construct(
        private BoardCreation $operation,
        private CreateBoardPlan $plan,
        private ResultBoundary $results,
        private PreviewBoundary $previews,
    ) {
    }

    public static function definition(): ToolDefinition {
        $d = [
            'name' => self::TOOL,
            'description' => ToolMessages::TOOL_CREATE_BOARD_DESCRIPTION,
            // A board that does not exist yet cannot be somebody else's, so there is no `confirm_shared`.
            'inputSchema' => DeckSchema::schema(
                [
                    'title' => DeckSchema::string(ToolMessages::PARAM_BOARD_TITLE, 1, BoardBlueprint::MAX_TITLE_LENGTH),
                    'color' => DeckSchema::string(ToolMessages::PARAM_BOARD_COLOR, 6, 7),
                    'stacks' => [
                        'type' => 'array',
                        'maxItems' => BoardBlueprint::MAX_STACKS,
                        'description' => ToolMessages::PARAM_BOARD_STACKS,
                        'items' => DeckSchema::schema(
                            [
                                'title' => DeckSchema::string(ToolMessages::PARAM_STACK_TITLE, 1, BoardBlueprint::MAX_TITLE_LENGTH),
                                'cards' => [
                                    'type' => 'array',
                                    'maxItems' => BoardBlueprint::MAX_CARDS,
                                    'description' => ToolMessages::PARAM_STACK_CARDS,
                                    'items' => DeckSchema::schema(
                                        [
                                            'title' => DeckSchema::string(ToolMessages::PARAM_TITLE, 1, 255),
                                            'description' => DeckSchema::string(ToolMessages::PARAM_DESCRIPTION, 0),
                                            'duedate' => DeckSchema::nullableString(ToolMessages::PARAM_DUEDATE),
                                            'assignees' => DeckSchema::accountList(ToolMessages::PARAM_ASSIGNEES),
                                        ],
                                        ['title'],
                                    ),
                                ],
                            ],
                            ['title'],
                        ),
                    ],
                ],
                ['title'],
            ),
            'module' => 'deck',
            'operation' => 'create',
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
