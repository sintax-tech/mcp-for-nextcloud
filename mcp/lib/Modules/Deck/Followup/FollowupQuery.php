<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Followup;

use OCA\Mcp\Modules\Deck\Access\DeckCaller;
use OCA\Mcp\Modules\Deck\Reading\BoardReader;
use OCA\Mcp\Modules\Deck\Assignments\AssignmentAccess;
use OCA\Mcp\Modules\Deck\Assignments\AssignedUsers;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\Stack;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Service\PermissionService;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\Mcp\Modules\Deck\Followup\CardCriteria;

class FollowupQuery {
    /** @param \Closure(): StackMapper $stackMapperProvider
     * @param \Closure(): CardMapper $cardMapperProvider
     * @param \Closure(): PermissionService $permissionServiceProvider */
    public function __construct(
        private DeckCaller $caller,
        private BoardReader $boards,
        private AssignmentAccess $assignments,
        private \Closure $stackMapperProvider,
        private \Closure $cardMapperProvider,
        private \Closure $permissionServiceProvider,
        private ITimeFactory $time,
        private ?UserTimezone $zones = null,
    ) {
    }

    private const MAX_BOARDS = 100;
    /**
     * Cards a manager follows up, from the boards that caller may manage.
     *
     * The deck has no such query, so the capability walks the boards the caller may manage (owner or
     * `PERMISSION_MANAGE`), reads their stacks and cards in batch, and filters with
     * {@see CardCriteria} while it goes: a board costs four queries plus one permission lookup and
     * a card never costs a query of its own. At most 100 visible boards are examined, and
     * `truncated` reports both a scan cut short by the limit and one cut short by that board
     * ceiling.
     *
     * @param string $userId UID of the authenticated caller, who must be able to manage the boards.
     * @param string $status One of {@see CardCriteria::STATUSES}.
     * @param int|null $boardId Restrict the scan to this board, or null for every managed board.
     * @param string|null $assignee Keep only cards assigned to this UID, or null for any assignee.
     * @param string|null $dueBefore Keep only cards due on or before this `YYYY-MM-DD`, or null.
     * @param int $limit Maximum number of cards to answer with.
     * @return array{items: list<array{card: Card, boardId: int}>, truncated: bool} Matching cards,
     *     each with the board it came from, and whether the answer may be incomplete.
     * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
     */
    public function followupCards(
        string $userId,
        string $status,
        ?int $boardId,
        ?string $assignee,
        ?string $dueBefore,
        int $limit,
    ): array {
        // `listBoards()` binds the caller to the Deck before anything is read for them.
        $managed = $this->managedBoards($userId, $boardId);
        $now = $this->time->getTime();
        $zone = $this->zone($userId);
        /** @var StackMapper $stackMapper */
        $stackMapper = ($this->stackMapperProvider)();
        /** @var CardMapper $cardMapper */
        $cardMapper = ($this->cardMapperProvider)();
        $truncated = $managed['capped'];
        $items = [];
        foreach ($managed['boards'] as $board) {
            // One card past the limit is proof the answer is incomplete, so the scan stops there.
            if (count($items) > $limit) {
                break;
            }
            $stackIds = array_map(static fn(Stack $stack): int => (int) $stack->getId(), $stackMapper->findAll((int) $board->getId()));
            if ($stackIds === []) {
                continue;
            }
            // Deck already leaves archived and deleted cards out.
            $matching = [];
            foreach ($this->cardsOfStacks($cardMapper, $stackIds) as $cardsOfStack) {
                foreach ($cardsOfStack ?? [] as $card) {
                    if (CardCriteria::matches($card, $status, $dueBefore, $now, $zone)) {
                        $matching[] = $card;
                    }
                }
            }
            if ($matching === []) {
                continue;
            }
            $this->assignments->attachAssignments($matching);
            foreach ($matching as $card) {
                if ($assignee !== null && !in_array($assignee, AssignedUsers::assignedUids($card), true)) {
                    continue;
                }
                $items[] = ['card' => $card, 'boardId' => (int) $board->getId()];
            }
        }
        if (count($items) > $limit) {
            $truncated = true;
            $items = array_slice($items, 0, $limit);
        }
        return ['items' => $items, 'truncated' => $truncated];
    }

    /**
     * Boards a follow-up scans: the ones the caller may manage, never the ones merely shared with
     * them, capped at {@see self::MAX_BOARDS} so the walk stays a bounded number of queries.
     *
     * `PermissionService::getPermissions()` already answers the owner as allowed
     * (`lib/Service/PermissionService.php:80` on Deck v1.17.5), so an explicit ACL lookup and
     * ownership are the same check here.
     *
     * @param string $userId UID of the authenticated caller.
     * @param int|null $boardId Restrict the scan to this board, or null for all of them.
     * @return array{boards: list<Board>, capped: bool} Boards to scan, and whether visible boards
     *     were left unexamined by the ceiling.
     */
    private function managedBoards(string $userId, ?int $boardId): array {
        /** @var PermissionService $permissionService */
        $boards = $this->boards->listBoards($userId);
        $permissionService = ($this->permissionServiceProvider)();
        if ($boardId !== null) {
            $boards = array_values(array_filter($boards, static fn(Board $board): bool => (int) $board->getId() === $boardId));
        }
        $capped = count($boards) > self::MAX_BOARDS;
        $managed = [];
        foreach (array_slice($boards, 0, self::MAX_BOARDS) as $board) {
            $permissions = $permissionService->getPermissions((int) $board->getId(), $userId);
            if (($permissions[Acl::PERMISSION_MANAGE] ?? false) === true) {
                $managed[] = $board;
            }
        }
        return ['boards' => $managed, 'capped' => $capped];
    }

    /**
     * Open cards of a board's stacks, grouped by stack in the order given.
     *
     * Deck 1.17 (Nextcloud 33) reads them in one query with `CardMapper::findAllForStacks()`; Deck 1.15 and 1.16
     * (Nextcloud 31 and 32) do not have it, so their cards are read stack by stack with `findAll()`, the query the
     * grouped one repeats: same filters (not archived, not deleted) and the same order (`order`, then `id`).
     *
     * @param object $cardMapper Deck's CardMapper, of whichever supported Deck is installed.
     * @param list<int> $stackIds Stacks of one board.
     * @return array<int, Card[]|null> Cards per stack id; a stack without cards may be null.
     */
    private function cardsOfStacks(object $cardMapper, array $stackIds): array {
        if (method_exists($cardMapper, 'findAllForStacks')) {
            return $cardMapper->findAllForStacks($stackIds) ?: [];
        }
        $cards = [];
        foreach ($stackIds as $stackId) {
            $found = $cardMapper->findAll($stackId);
            $cards[$stackId] = is_array($found) ? array_values($found) : [];
        }
        return $cards;
    }

    /**
     * Timezone the caller reads due dates in.
     *
     * @param string $userId UID of the authenticated caller.
     * @return \DateTimeZone Their preference, or PHP's default when no resolver was given.
     */
    private function zone(string $userId): \DateTimeZone {
        return $this->zones?->forUser($userId) ?? new \DateTimeZone(date_default_timezone_get());
    }
}
