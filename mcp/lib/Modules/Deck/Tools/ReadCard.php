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

class ReadCard implements Tool {
    public const TOOL = 'deck_read_card';
    /** @param \Closure(string, int): \OCA\Deck\Db\Card $query Capability query bound by the composition root. */
    public function __construct(
        private \Closure $query,
        private ResultBoundary $results,
        private DeckFormatterFactory $formatters,
    ) {
    }

    public static function definition(): ToolDefinition {
        $d = [
            'name' => self::TOOL,
            'description' => ToolMessages::TOOL_READ_CARD_DESCRIPTION,
            'inputSchema' => DeckSchema::schema(['cardId' => DeckSchema::integer(ToolMessages::PARAM_CARD_ID)], ['cardId']),
            'module' => 'deck',
            'operation' => 'read',
            'app' => 'deck',
        ];
        return new ToolDefinition($d['name'], $d['module'], $d['operation'], $d['description'], $d['inputSchema'], $d['app']);
    }

    /**
     * The payload is the card itself, with `description` in full.
     *
     * @param array<string, mixed> $arguments Requires `cardId`.
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
                return $formatter->card(($this->query)($userId, (int) $arguments['cardId']));
            },
        );
    }
}
