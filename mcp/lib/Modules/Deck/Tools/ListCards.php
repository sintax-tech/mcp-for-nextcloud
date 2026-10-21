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
use OCA\Deck\Db\Card;

class ListCards implements Tool {
    public const TOOL = 'deck_list_cards';
    public const DEFAULT_LIMIT = 50;
    /** @param \Closure(string, int, int, int): array $query Capability query bound by the composition root. */
    public function __construct(
        private \Closure $query,
        private ResultBoundary $results,
        private DeckFormatterFactory $formatters,
    ) {
    }

    public static function definition(): ToolDefinition {
        $d = [
            'name' => self::TOOL,
            'description' => ToolMessages::TOOL_LIST_CARDS_DESCRIPTION,
            'inputSchema' => DeckSchema::schema(
                [
                    'stackId' => DeckSchema::integer(ToolMessages::PARAM_STACK_ID),
                    'limit' => DeckSchema::integer(ToolMessages::PARAM_LIMIT, 1, 200, self::DEFAULT_LIMIT),
                    'offset' => DeckSchema::integer(ToolMessages::PARAM_OFFSET, 0),
                ],
                ['stackId'],
            ),
            'module' => 'deck',
            'operation' => 'read',
            'app' => 'deck',
        ];
        return new ToolDefinition($d['name'], $d['module'], $d['operation'], $d['description'], $d['inputSchema'], $d['app']);
    }

    /**
     * The payload is `{cards: list<card>, limit: int, offset: int, total: int|null}`, where `total`
     * is only known when the page came back short (cards before the offset plus the page). An empty page
     * past the first one leaves it null: the stack ends somewhere before the offset, and the offset is not it.
     *
     * @param array<string, mixed> $arguments Requires `stackId`; accepts `limit` and `offset`.
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
                $limit = (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT);
                $offset = (int) ($arguments['offset'] ?? 0);
                $page = ($this->query)($userId, (int) $arguments['stackId'], $limit, $offset);
                $cards = array_values(array_filter($page['items'], fn(Card $card) => $formatter->isListed($card)));
                return [
                    'cards' => array_map(fn(Card $card) => $formatter->card($card, $page['boardId']), $cards),
                    'limit' => $limit,
                    'offset' => $offset,
                    // Deck skips deleted and archived cards in the query itself, so the offset counts listed cards:
                    // a page that came back short ends the stack, and the total is what precedes it plus the page.
                    // With no card on it past the first page, what precedes it is not known.
                    'total' => count($page['items']) < $limit && ($offset === 0 || $cards !== []) ? $offset + count($cards) : null,
                ];
            },
        );
    }
}
