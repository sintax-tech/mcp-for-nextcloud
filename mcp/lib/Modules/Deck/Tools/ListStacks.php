<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Tools;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Modules\Deck\Execution\ResultBoundary;
use OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Deck\Presentation\ToolMessages;
use OCA\Mcp\Modules\Deck\Presentation\DeckSchema;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Deck\Db\Stack;

class ListStacks implements Tool {
    public const TOOL = 'deck_list_stacks';
    public const MAX_STACKS = 200;
    /** @param \Closure(string, int): array $query Capability query bound by the composition root. */
    public function __construct(
        private \Closure $query,
        private ResultBoundary $results,
        private DeckFormatterFactory $formatters,
    ) {
    }

    public static function definition(): ToolDefinition {
        $d = [
            'name' => self::TOOL,
            'description' => ToolMessages::TOOL_LIST_STACKS_DESCRIPTION,
            'inputSchema' => DeckSchema::schema(['boardId' => DeckSchema::integer(ToolMessages::PARAM_BOARD_ID)], ['boardId']),
            'module' => 'deck',
            'operation' => 'read',
            'app' => 'deck',
        ];
        return new ToolDefinition($d['name'], $d['module'], $d['operation'], $d['description'], $d['inputSchema'], $d['app']);
    }

    /**
     * The payload is `{stacks: list<{id, boardId, title, order, cardsCount}>, truncated: bool}`,
     * with at most {@see self::MAX_STACKS} stacks.
     *
     * @param array<string, mixed> $arguments Requires `boardId`.
     * @param string $userId UID of the authenticated caller.
     * @return array{content: list<array{type: string, text: string}>, isError?: bool} MCP result.
     */
    public function execute(array $arguments, ToolContext $context): array {
        $userId = $context->userId;
        $formatter = $this->formatters->forUser($userId);
        return $this->results->run(
            self::TOOL,
            'deck_read_card',
            function () use ($arguments, $userId, $formatter): array {
                $stacks = ($this->query)($userId, (int) $arguments['boardId']);
                $truncated = count($stacks) > self::MAX_STACKS;
                $stacks = array_slice($stacks, 0, self::MAX_STACKS);
                return [
                    'stacks' => array_map(fn(Stack $stack) => $formatter->stack($stack), $stacks),
                    'truncated' => $truncated,
                ];
            },
        );
    }
}
