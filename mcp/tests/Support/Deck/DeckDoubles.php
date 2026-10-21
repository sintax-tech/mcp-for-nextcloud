<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Support\Deck;

/** Narrow collaborators used by capability tests. */

class DeckDoubles {
    public \OCA\Mcp\Modules\Deck\Reading\BoardReader $boards;
    public \OCA\Mcp\Modules\Deck\Reading\StackReader $stacks;
    public \OCA\Mcp\Modules\Deck\Reading\CardReader $cards;
    public \OCA\Mcp\Modules\Deck\Cards\CardWriter $writer;
    public \OCA\Mcp\Modules\Deck\Cards\CardStateReader $state;
    public \OCA\Mcp\Modules\Deck\Assignments\AssignmentAccess $assignments;
    public \OCA\Mcp\Modules\Deck\Access\OwnershipReader $ownership;
    public \OCA\Mcp\Modules\Deck\Structure\BoardCreator $boardCreator;
    public \OCA\Mcp\Modules\Deck\Structure\StackCreator $stackCreator;
    public \OCA\Mcp\Modules\Deck\Structure\StructureStateReader $structureState;
    public \OCA\Mcp\Modules\Deck\Structure\StructureCardCounter $counts;
    public \OCA\Mcp\Modules\Deck\Structure\EmptyStructureDeletion $deletion;
    public \OCA\Mcp\Modules\Deck\Followup\FollowupQuery $followup;
}
