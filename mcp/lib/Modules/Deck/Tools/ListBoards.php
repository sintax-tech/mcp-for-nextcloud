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
use OCA\Deck\Db\Board;

class ListBoards implements Tool {
    public const TOOL = 'deck_list_boards';
    public const MAX_BOARDS = 200;
    /** @param \Closure(string): array $query Capability query bound by the composition root. */
    public function __construct(
        private \Closure $query,
        private ResultBoundary $results,
        private DeckFormatterFactory $formatters,
    ) {
    }

    public static function definition(): ToolDefinition {
        $d = [
            'name' => self::TOOL,
            'description' => ToolMessages::TOOL_LIST_BOARDS_DESCRIPTION,
            'inputSchema' => DeckSchema::emptySchema(),
            'module' => 'deck',
            'operation' => 'read',
            'app' => 'deck',
        ];
        return new ToolDefinition($d['name'], $d['module'], $d['operation'], $d['description'], $d['inputSchema'], $d['app']);
    }

    /**
     * The payload is `{boards: list<{id, title, color, archived, owner, lastModified}>, truncated: bool}`,
     * with at most {@see self::MAX_BOARDS} boards.
     *
     * @param array<string, mixed> $arguments Unused; the tool takes no arguments.
     * @param string $userId UID of the authenticated caller.
     * @return array{content: list<array{type: string, text: string}>, isError?: bool} MCP result.
     */
    public function execute(array $arguments, ToolContext $context): array {
        $userId = $context->userId;
        $formatter = $this->formatters->forUser($userId);
        return $this->results->run(
            self::TOOL,
            'deck_read_card',
            function () use ($userId, $formatter): array {
                $boards = ($this->query)($userId);
                $truncated = count($boards) > self::MAX_BOARDS;
                $boards = array_slice($boards, 0, self::MAX_BOARDS);
                return [
                    'boards' => array_map(fn(Board $board) => $formatter->board($board), $boards),
                    'truncated' => $truncated,
                ];
            },
        );
    }
}
