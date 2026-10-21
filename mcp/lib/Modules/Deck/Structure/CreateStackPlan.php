<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Structure;

use OCA\Mcp\Modules\Deck\Access\OwnershipReader;
use OCA\Mcp\Modules\Deck\Presentation\PlanLocation;

class CreateStackPlan {
    public function __construct(private OwnershipReader $ownership) {
    }

    /**
     * @param array{boardId: int, title: string, order?: int} $arguments
     * @param string $userId UID of the authenticated caller.
     * @return array<string, mixed>
     */
    public function build(array $arguments, string $userId): array {
        $title = BoardBlueprint::title((string) $arguments['title'], 'title');
        $board = PlanLocation::board($this->ownership->boardOwnership($userId, (int) $arguments['boardId']), $userId);
        return [
            'action' => 'deck_create_stack',
            'stack' => [
                'title' => $title,
                'order' => array_key_exists('order', $arguments) ? (int) $arguments['order'] : null,
            ],
            'board' => $board,
            'shared' => $board['shared'] ? [$board] : [],
            'recoverable' => true,
            'message' => StructureMessages::planCreateStack(),
        ];
    }
}
