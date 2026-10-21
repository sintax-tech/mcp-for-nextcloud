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
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\PermissionService;
use OCP\AppFramework\Utility\ITimeFactory;

class CardStateReader {
    /** @param \Closure(): PermissionService $permissionServiceProvider
     * @param \Closure(): CardMapper $cardMapperProvider
     * @param \Closure(): StackMapper $stackMapperProvider */
    public function __construct(
        private DeckCaller $caller,
        private \Closure $permissionServiceProvider,
        private \Closure $cardMapperProvider,
        private \Closure $stackMapperProvider,
        private ITimeFactory $time,
    ) {
    }

    private const CREATED_WITHIN = 120;
    /**
     * `CardMapper::find()` runs a fresh query and, unlike `CardService::find()`, answers a deleted card too.
     */
    public function cardState(string $userId, int $cardId): Card {
        $this->caller->bind($userId);
        /** @var PermissionService $permissionService */
        $permissionService = ($this->permissionServiceProvider)();
        /** @var CardMapper $cardMapper */
        $cardMapper = ($this->cardMapperProvider)();
        // Deck v1.17.5 `checkPermission($mapper, $id, $permission, $userId, $allowDeletedCard)`: a deleted card is
        // exactly what a delete leaves, so it stays readable here.
        $permissionService->checkPermission($cardMapper, $cardId, Acl::PERMISSION_READ, null, true);
        return $cardMapper->find($cardId, false);
    }

    /**
     * The card a creation that threw may have saved: the newest card of the caller in that list with that title,
     * created in the last two minutes and not one of `$excludeIds`.
     *
     * @param string $userId UID of the authenticated caller, the owner of the card.
     * @param int $stackId List the card was created in.
     * @param string $title Title of the card.
     * @param list<int> $excludeIds Cards already accounted for, such as the ones created earlier in the same call.
     * @return Card|null The card, or null when there is none.
     * @throws \Throwable Any Deck failure; the caller treats it as "cannot tell".
     */
    public function findCreatedCard(
        string $userId,
        int $stackId,
        string $title,
        array $excludeIds = [],
    ): ?Card {
        $this->caller->bind($userId);
        /** @var PermissionService $permissionService */
        $permissionService = ($this->permissionServiceProvider)();
        /** @var StackMapper $stackMapper */
        $stackMapper = ($this->stackMapperProvider)();
        /** @var CardMapper $cardMapper */
        $cardMapper = ($this->cardMapperProvider)();
        $permissionService->checkPermission($stackMapper, $stackId, Acl::PERMISSION_READ);
        $since = $this->time->getTime() - self::CREATED_WITHIN;
        $found = null;
        foreach ($cardMapper->findAll($stackId) ?: [] as $card) {
            if (!$card instanceof Card || in_array((int) $card->getId(), $excludeIds, true)) {
                continue;
            }
            if ((string) $card->getOwner() === $userId && (string) $card->getTitle() === $title && (int) $card->getCreatedAt() >= $since && ($found === null || (int) $card->getId() > (int) $found->getId())) {
                $found = $card;
            }
        }
        return $found;
    }
}
