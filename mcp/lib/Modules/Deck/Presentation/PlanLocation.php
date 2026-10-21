<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Presentation;

use OCA\Mcp\Modules\Deck\Access\OwnershipReader;
use OCA\Mcp\Modules\Deck\Reading\BoardReader;
use OCA\Mcp\Modules\Deck\Reading\StackReader;

class PlanLocation {
    public function __construct(
        private OwnershipReader $ownership,
        private BoardReader $boards,
        private StackReader $stacks,
    ) {
    }

    /**
     * Where a card lands or comes from: the list, the board behind it and who owns that board.
     *
     * @param string $userId UID of the authenticated caller.
     * @param int $stackId Deck list the plan is about.
     * @return array{stackId: int, list: string|null, board: string, owner: string, ownerDisplayName: string, shared: bool}
     */
    public function place(string $userId, int $stackId): array {
        $ownership = $this->ownership->stackOwnership($userId, $stackId);
        return [
            'stackId' => $stackId,
            'list' => $this->listTitle($userId, $stackId),
            'board' => $ownership['name'],
            'owner' => $ownership['owner'],
            'ownerDisplayName' => $ownership['ownerDisplayName'],
            'shared' => $ownership['owner'] !== $userId,
        ];
    }

    /**
     * @param array{owner: string, ownerDisplayName: string, name: string} $ownership
     * @param string $userId UID of the authenticated caller.
     * @return array{board: string, owner: string, ownerDisplayName: string, shared: bool}
     */
    public static function board(array $ownership, string $userId): array {
        return [
            'board' => $ownership['name'],
            'owner' => $ownership['owner'],
            'ownerDisplayName' => $ownership['ownerDisplayName'],
            'shared' => $ownership['owner'] !== $userId,
        ];
    }

    /**
     * Title of the Deck list a stack id names, or null when it cannot be read.
     *
     * The Deck capability has no single-stack query, and a plan is not a hot path: the visible boards are
     * walked once and the first list that matches wins.
     *
     * @param string $userId UID of the authenticated caller.
     * @param int $stackId Deck list to name.
     * @return string|null The list title, or null when no visible board holds it.
     */
    public function listTitle(string $userId, int $stackId): ?string {
        foreach ($this->boards->listBoards($userId) as $board) {
            foreach ($this->stacks->listStacks($userId, (int) $board->getId()) as $stack) {
                if ((int) $stack->getId() === $stackId) {
                    return (string) $stack->getTitle();
                }
            }
        }
        return null;
    }
}
