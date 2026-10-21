<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Cards;

use OCA\Mcp\Modules\Deck\Access\DeckCaller;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Model\OptionalNullableValue;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\PermissionService;
use OCA\Mcp\Platform\Time\UserTimezone;

class CardWriter {
    /** @param \Closure(): CardService $cardServiceProvider
     * @param \Closure(): PermissionService $permissionServiceProvider
     * @param \Closure(): StackMapper $stackMapperProvider
     * @param \Closure(): CardMapper $cardMapperProvider */
    public function __construct(
        private DeckCaller $caller,
        private \Closure $cardServiceProvider,
        private \Closure $permissionServiceProvider,
        private \Closure $stackMapperProvider,
        private \Closure $cardMapperProvider,
        private ?UserTimezone $zones = null,
    ) {
    }

    private const LAST_ORDER = 99999;
    /**
     * Creates a card owned by the caller at the end of the stack.
     *
     * @param string $userId UID of the authenticated caller, used as the card owner.
     * @param int $stackId Stack that receives the card.
     * @param string $title Card title.
     * @param string $description Card description, possibly empty.
     * @param string|null $duedate Due date as `YYYY-MM-DD` (midnight for the caller), or null for no date.
     * @param int|null $order Position in the stack, or null to go after the last card.
     * @return Card Created card.
     * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
     */
    public function createCard(
        string $userId,
        int $stackId,
        string $title,
        string $description,
        ?string $duedate,
        ?int $order = null,
    ): Card {
        $this->caller->bind($userId);
        /** @var CardService $cardService */
        $cardService = ($this->cardServiceProvider)();
        // `type` is free text in Deck (only length-checked), so the tools always write `note`.
        return $cardService->create(
            $title,
            $stackId,
            'note',
            $order ?? self::LAST_ORDER,
            $userId,
            $description,
            $this->instant($userId, $duedate),
        );
    }

    /**
     * Writes the full card form, as `CardService::update()` requires it.
     *
     * @param string $userId UID of the authenticated caller.
     * @param Card $card Card as previously read, source of the fields the caller did not change.
     * @param string $title New title.
     * @param string $description New description.
     * @param string|null $duedate New due date as `YYYY-MM-DD` (midnight for the caller), the current
     *     ISO 8601 instant to keep it untouched, or null to clear it.
     * @return Card Updated card.
     * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
     */
    public function updateCard(
        string $userId,
        Card $card,
        string $title,
        string $description,
        ?string $duedate,
    ): Card {
        $this->caller->bind($userId);
        /** @var CardService $cardService */
        $cardService = ($this->cardServiceProvider)();
        // Deck v1.17.5 nulls `done` when the optional argument is left out, so the current value is
        // sent back wrapped to preserve it; every other field the form requires comes from the card.
        return $cardService->update(
            $card->getId(),
            $title,
            $card->getStackId(),
            $card->getType(),
            (string) $card->getOwner(),
            $description,
            $card->getOrder(),
            $this->instant($userId, $duedate),
            null,
            null,
            new OptionalNullableValue($card->getDone()),
            ...CardUpdateArguments::fieldsAfterDone($cardService, $card),
        );
    }

    /**
     * Moves a card to another stack, possibly of another board.
     *
     * @param string $userId UID of the authenticated caller.
     * @param int $cardId Card to move.
     * @param int $stackId Destination stack.
     * @param int|null $order Target position, or null to append at the end of the destination.
     * @return Card Moved card.
     * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
     */
    public function moveCard(
        string $userId,
        int $cardId,
        int $stackId,
        ?int $order,
    ): Card {
        $this->caller->bind($userId);
        /** @var PermissionService $permissionService */
        $permissionService = ($this->permissionServiceProvider)();
        /** @var StackMapper $stackMapper */
        $stackMapper = ($this->stackMapperProvider)();
        /** @var CardMapper $cardMapper */
        $cardMapper = ($this->cardMapperProvider)();
        /** @var CardService $cardService */
        $cardService = ($this->cardServiceProvider)();
        if ($order === null) {
            // Reading the destination stack is an access of its own: check READ before the query.
            $permissionService->checkPermission($stackMapper, $stackId, Acl::PERMISSION_READ);
            $order = $this->nextOrder($cardMapper->findAll($stackId));
        }
        // `reorder()` checks PERMISSION_EDIT on the card and on the destination stack.
        $cards = $cardService->reorder($cardId, $stackId, $order);
        $moved = null;
        foreach ($cards as $card) {
            if ((int) $card->getId() === $cardId) {
                $moved = $card;
                break;
            }
        }
        return $moved ?? $cardService->find($cardId);
    }

    /**
     * Soft deletes a card, exactly as the Deck web interface does.
     *
     * @param string $userId UID of the authenticated caller.
     * @param int $cardId Card to delete.
     * @return Card Deleted card, still readable by the Deck.
     * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
     */
    public function deleteCard(string $userId, int $cardId): Card {
        $this->caller->bind($userId);
        /** @var CardService $cardService */
        $cardService = ($this->cardServiceProvider)();
        // Soft delete: Deck stamps `deleted_at` and keeps the row.
        return $cardService->delete($cardId);
    }

    /**
     * Last free position of a stack, so a card without an explicit order lands at the bottom.
     *
     * @param mixed $cards Cards returned by `CardMapper::findAll()`.
     * @return int Position to use for the moved card.
     */
    private function nextOrder(mixed $cards): int {
        $highest = -1;
        foreach (is_array($cards) ? $cards : [] as $card) {
            if ($card instanceof Card) {
                $highest = max($highest, (int) $card->getOrder());
            }
        }
        return $highest + 1;
    }

    /**
     * Due date as Deck should store it.
     *
     * A bare `YYYY-MM-DD` from the tools becomes midnight of that day for the caller, since Deck would
     * otherwise read it as UTC midnight and the card would show up a day early west of Greenwich. A
     * full timestamp (the current date kept by an edit that did not touch it) is passed through, so
     * the time set in the Deck web interface survives.
     *
     * @param string $userId UID of the authenticated caller.
     * @param string|null $duedate Day, ISO 8601 timestamp, or null to clear the date.
     * @return string|null Value for `CardService`.
     */
    private function instant(string $userId, ?string $duedate): ?string {
        if ($duedate === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $duedate) !== 1) {
            return $duedate;
        }
        return CardDate::localMidnight($duedate, $this->zone($userId));
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
