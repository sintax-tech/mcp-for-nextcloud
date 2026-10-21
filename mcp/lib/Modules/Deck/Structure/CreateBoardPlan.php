<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Structure;

use OCP\IUserManager;
use InvalidArgumentException;

class CreateBoardPlan {
    public function __construct(private ?IUserManager $userManager = null) {
    }

    /**
     * The tree of a board as it will be built: the board, its lists and their cards, validated by the same
     * {@see BoardBlueprint} the execution reads, so anything the write would refuse is refused here.
     *
     * @param array<string, mixed> $arguments Arguments of `deck_create_board`.
     * @param string $userId UID of the authenticated caller, the owner of the board.
     * @return array<string, mixed>
     * @throws InvalidArgumentException Naming the field when the structure is refused.
     */
    public function build(array $arguments, string $userId): array {
        $blueprint = BoardBlueprint::parse($arguments, $userId);
        // Only the owner can be assigned on a new board, and BoardBlueprint has already refused anybody else.
        $self = ['uid' => $userId, 'displayName' => $this->userManager?->get($userId)?->getDisplayName() ?: $userId];
        return [
            'action' => 'deck_create_board',
            'board' => [
                'title' => $blueprint['title'],
                'color' => $blueprint['color'],
                'colorGiven' => $blueprint['colorGiven'],
            ],
            'owner' => $userId,
            'stacks' => array_map(
                static fn(array $stack): array => [
                    'title' => $stack['title'],
                    'cards' => array_map(
                        static fn(array $card): array => [
                            'title' => $card['title'],
                            'description' => $card['description'],
                            'duedate' => $card['duedate'],
                            'assignees' => array_map(static fn(string $uid): array => $self, $card['assignees']),
                        ],
                        $stack['cards'],
                    ),
                ],
                $blueprint['stacks'],
            ),
            'totals' => ['stacks' => count($blueprint['stacks']), 'cards' => $blueprint['cardCount']],
            'limits' => ['stacks' => BoardBlueprint::MAX_STACKS, 'cards' => BoardBlueprint::MAX_CARDS],
            'shared' => [],
            'recoverable' => true,
            'message' => StructureMessages::planCreateBoard(),
        ];
    }
}
