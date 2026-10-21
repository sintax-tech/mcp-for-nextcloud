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
use OCA\Mcp\Modules\Deck\Execution\ErrorMessages;
use OCA\Mcp\Modules\Deck\Presentation\DeckFormatter;
use InvalidArgumentException;
use OCA\Mcp\Modules\Deck\Followup\CardCriteria;
use OCA\Mcp\Modules\Deck\Cards\CardInput;

class FollowupCards implements Tool {
    public const TOOL = 'deck_followup_cards';
    public const DEFAULT_LIMIT = 100;
    public const MAX_LIMIT = 500;
    /** @param \Closure(string, string, ?int, ?string, ?string, int): array $query Capability query bound by the composition root. */
    public function __construct(
        private \Closure $query,
        private ResultBoundary $results,
        private DeckFormatterFactory $formatters,
    ) {
    }

    public static function definition(): ToolDefinition {
        $d = [
            'name' => self::TOOL,
            'description' => ToolMessages::TOOL_FOLLOWUP_CARDS_DESCRIPTION,
            // Every argument is optional: the defaults answer the question the tool exists for.
            'inputSchema' => DeckSchema::schema(
                [
                    'status' => [
                        'type' => 'string',
                        'enum' => CardCriteria::STATUSES,
                        'default' => CardCriteria::STATUS_OVERDUE,
                        'description' => ToolMessages::PARAM_STATUS,
                    ],
                    'assignee' => DeckSchema::string(ToolMessages::PARAM_ASSIGNEE, 1, 64),
                    'boardId' => DeckSchema::integer(ToolMessages::PARAM_BOARD_ID),
                    'dueBefore' => ['type' => 'string', 'description' => ToolMessages::PARAM_DUE_BEFORE],
                    'limit' => DeckSchema::integer(ToolMessages::PARAM_LIMIT, 1, self::MAX_LIMIT, self::DEFAULT_LIMIT),
                ],
                [],
            ),
            'module' => 'deck',
            'operation' => 'read',
            'app' => 'deck',
        ];
        return new ToolDefinition($d['name'], $d['module'], $d['operation'], $d['description'], $d['inputSchema'], $d['app']);
    }

    /**
     * The payload is `{groups: list<{assignee: {uid, displayName}|null, cards: list<card>}>,
     * truncated: bool}`: one group per responsible person sorted by UID, with the group of cards
     * nobody is assigned to (`assignee: null`) leading, since it sorts as the empty UID. A card
     * assigned to several people appears in each of their groups, and `limit` counts cards before
     * that repetition.
     *
     * @param array<string, mixed> $arguments `status`, `assignee`, `boardId`, `dueBefore`, `limit`.
     * @param string $userId UID of the authenticated caller.
     * @return array{content: list<array{type: string, text: string}>, isError?: bool} MCP result.
     * @throws InvalidArgumentException When `status` or `dueBefore` is not a value the tool accepts.
     */
    public function execute(array $arguments, ToolContext $context): array {
        $userId = $context->userId;
        $formatter = $this->formatters->forUser($userId);
        return $this->results->run(
            self::TOOL,
            'deck_read_card',
            function () use ($arguments, $userId, $formatter): array {
                $status = (string) ($arguments['status'] ?? CardCriteria::STATUS_OVERDUE);
                if (!in_array($status, CardCriteria::STATUSES, true)) {
                    throw new InvalidArgumentException(ErrorMessages::errorInvalidStatus());
                }
                $page = ($this->query)(
                    $userId,
                    $status,
                    isset($arguments['boardId']) ? (int) $arguments['boardId'] : null,
                    isset($arguments['assignee']) ? (string) $arguments['assignee'] : null,
                    CardInput::dueBefore($arguments['dueBefore'] ?? null),
                    max(1, min(self::MAX_LIMIT, (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT))),
                );
                return [
                    'groups' => array_values($this->groupByAssignee($page['items'], $formatter)),
                    'truncated' => $page['truncated'],
                ];
            },
        );
    }

    /**
     * Buckets the answer by the people responsible for each card, one bucket per person.
     *
     * @param list<array{card: \OCA\Deck\Db\Card, boardId: int}> $items Cards the capability returned.
     * @return array<string, array{assignee: array{uid: string, displayName: string}|null, cards: list<array<string, mixed>>}>
     *     Buckets keyed by UID, empty string for the cards nobody is assigned to.
     */
    private function groupByAssignee(array $items, DeckFormatter $formatter): array {
        $groups = [];
        foreach ($items as $item) {
            $card = $formatter->card($item['card'], $item['boardId']);
            $assignees = $card['assignedUsers'];
            if ($assignees === []) {
                $groups[''] ??= ['assignee' => null, 'cards' => []];
                $groups['']['cards'][] = $card;
                continue;
            }
            foreach ($assignees as $assignee) {
                $groups[$assignee['uid']] ??= ['assignee' => $assignee, 'cards' => []];
                $groups[$assignee['uid']]['cards'][] = $card;
            }
        }
        // The empty key of the unassigned group sorts first, and the people follow as plain UIDs.
        ksort($groups, SORT_STRING);
        return $groups;
    }
}
