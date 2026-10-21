<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Reading;

use OCA\Mcp\Modules\Deck\Access\DeckCaller;
use OCA\Mcp\Modules\Deck\Assignments\AssignmentAccess;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\PermissionService;

class CardReader {
    /** @param \Closure(): PermissionService $permissionServiceProvider
     * @param \Closure(): StackMapper $stackMapperProvider
     * @param \Closure(): CardMapper $cardMapperProvider
     * @param \Closure(): CardService $cardServiceProvider */
    public function __construct(
        private DeckCaller $caller,
        private AssignmentAccess $assignments,
        private \Closure $permissionServiceProvider,
        private \Closure $stackMapperProvider,
        private \Closure $cardMapperProvider,
        private \Closure $cardServiceProvider,
    ) {
    }

    /**
     * Active cards of one stack, paginated.
     *
     * The Deck has no service method for this, so the capability runs the same explicit
     * `PERMISSION_READ` check that `StackService::findAll()` runs before touching the mapper.
     *
     * @param string $userId UID of the authenticated caller.
     * @param int $stackId Stack to list.
     * @param int $limit Maximum number of cards.
     * @param int $offset Number of cards to skip.
     * @return array{items: list<Card>, boardId: int} Cards and the board they belong to.
     * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
     */
    public function listCards(
        string $userId,
        int $stackId,
        int $limit,
        int $offset,
    ): array {
        $this->caller->bind($userId);
        /** @var PermissionService $permissionService */
        $permissionService = ($this->permissionServiceProvider)();
        /** @var StackMapper $stackMapper */
        $stackMapper = ($this->stackMapperProvider)();
        /** @var CardMapper $cardMapper */
        $cardMapper = ($this->cardMapperProvider)();
        // CardMapper::findAll() is a plain query with no ACL at all, so the check comes first.
        $permissionService->checkPermission($stackMapper, $stackId, Acl::PERMISSION_READ);
        $items = $cardMapper->findAll($stackId, $limit, $offset);
        $items = is_array($items) ? array_values($items) : [];
        // Deck embeds the assignments of a card only on the detail read, so the listing loads them
        // in one query for the whole page instead of one query per card.
        $this->assignments->attachAssignments($items);
        return ['items' => $items, 'boardId' => $stackMapper->findBoardId($stackId)];
    }

    /**
     * One card, with attachments and counters enriched by the Deck.
     *
     * @param string $userId UID of the authenticated caller.
     * @param int $cardId Card to read.
     * @return Card Enriched card.
     * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
     */
    public function findCard(string $userId, int $cardId): Card {
        $this->caller->bind($userId);
        /** @var CardService $cardService */
        $cardService = ($this->cardServiceProvider)();
        // `CardService::find()` checks PERMISSION_READ and refuses a deleted card.
        return $cardService->find($cardId);
    }

    /**
     * Board a card belongs to, after the read check a link to the card needs.
     *
     * Talk cites a card by its canonical URL, which carries the board id; the check comes first so a
     * caller without read access learns nothing, not even which board the card is on.
     *
     * @param string $userId UID of the authenticated caller.
     * @param int $cardId Card whose board is looked up.
     * @return int Board id.
     * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
     */
    public function cardBoardId(string $userId, int $cardId): int {
        $this->caller->bind($userId);
        /** @var PermissionService $permissionService */
        $permissionService = ($this->permissionServiceProvider)();
        /** @var CardMapper $cardMapper */
        $cardMapper = ($this->cardMapperProvider)();
        // `CardMapper::findBoardId()` is a plain query, so the read check the card itself needs comes first.
        $permissionService->checkPermission($cardMapper, $cardId, Acl::PERMISSION_READ);
        return (int) $cardMapper->findBoardId($cardId);
    }
}
