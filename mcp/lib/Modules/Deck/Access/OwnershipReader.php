<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Access;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\PermissionService;
use OCP\IUserManager;

class OwnershipReader {
    /** @param \Closure(): PermissionService $permissionServiceProvider
     * @param \Closure(): StackMapper $stackMapperProvider
     * @param \Closure(): CardMapper $cardMapperProvider
     * @param \Closure(): BoardService $boardServiceProvider
     * @param \Closure(): IUserManager $iUserManagerProvider */
    public function __construct(
        private DeckCaller $caller,
        private \Closure $permissionServiceProvider,
        private \Closure $stackMapperProvider,
        private \Closure $cardMapperProvider,
        private \Closure $boardServiceProvider,
        private \Closure $iUserManagerProvider,
    ) {
    }

    /**
     * Ownership of the board behind a stack, after the edit check the write needs.
     *
     * The Deck has no owner query for a stack, so the capability checks `PERMISSION_EDIT` on the board
     * the stack belongs to first: a stack the caller may not write is answered as "denied" before
     * any owner or title is read, exactly like the write itself would.
     *
     * @param string $userId UID of the authenticated caller.
     * @param int $stackId Stack whose board is looked up.
     * @return array{owner: string, ownerDisplayName: string, name: string} Owner uid, its display name and the board title.
     * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
     */
    public function stackOwnership(string $userId, int $stackId): array {
        $this->caller->bind($userId);
        /** @var PermissionService $permissionService */
        $permissionService = ($this->permissionServiceProvider)();
        /** @var StackMapper $stackMapper */
        $stackMapper = ($this->stackMapperProvider)();
        // Check first: Deck answers a missing or unreadable stack with the same exception it uses
        // for a denied one, so no owner or title is read before the caller may write there.
        $permissionService->checkPermission($stackMapper, $stackId, Acl::PERMISSION_EDIT);
        return $this->ownershipOfBoard($stackMapper->findBoardId($stackId));
    }

    /**
     * Ownership of the board behind a card, after the edit check the write needs.
     *
     * Same contract as {@see self::stackOwnership()}, starting from the card: the Deck checks
     * `PERMISSION_EDIT` on the board of the card and refuses a card that is already deleted.
     *
     * @param string $userId UID of the authenticated caller.
     * @param int $cardId Card whose board is looked up.
     * @return array{owner: string, ownerDisplayName: string, name: string} Owner uid, its display name and the board title.
     * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
     */
    public function cardOwnership(string $userId, int $cardId): array {
        $this->caller->bind($userId);
        /** @var PermissionService $permissionService */
        $permissionService = ($this->permissionServiceProvider)();
        /** @var CardMapper $cardMapper */
        $cardMapper = ($this->cardMapperProvider)();
        // The same check `CardService::update()` and `CardService::delete()` run, taken before the
        // read so a caller without edit access never learns who owns the board.
        $permissionService->checkPermission($cardMapper, $cardId, Acl::PERMISSION_EDIT);
        return $this->ownershipOfBoard($cardMapper->findBoardId($cardId));
    }

    /**
     * Ownership of a board, after the manage check a structure change needs.
     *
     * Same contract as {@see self::stackOwnership()}, starting from the board: the check comes first, so a
     * caller who may not manage it learns neither its owner nor its title.
     *
     * @param string $userId UID of the authenticated caller.
     * @param int $boardId Board whose owner is looked up.
     * @return array{owner: string, ownerDisplayName: string, name: string} Owner uid, its display name and the board title.
     * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
     */
    public function boardOwnership(string $userId, int $boardId): array {
        $this->caller->bind($userId);
        /** @var PermissionService $permissionService */
        $permissionService = ($this->permissionServiceProvider)();
        // Check first: the owner and the title are read only for somebody who may manage the board.
        $permissionService->checkPermission(null, $boardId, Acl::PERMISSION_MANAGE);
        return $this->ownershipOfBoard($boardId);
    }

    /**
     * Owner, display name and title of a board, read after its permission check succeeded.
     *
     * @param mixed $boardId Board id resolved by a Deck mapper, or null when the entity is gone.
     * @return array{owner: string, ownerDisplayName: string, name: string} Ownership of the board.
     * @throws NoPermissionException When the board cannot be resolved, as the Deck answers it.
     * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
     */
    private function ownershipOfBoard(mixed $boardId): array {
        if ($boardId === null) {
            // Reached only if Deck and this lookup disagree; answer like Deck does, without leaking.
            throw new NoPermissionException('Permission denied');
        }
        /** @var BoardService $boardService */
        $boardService = ($this->boardServiceProvider)();
        // `fullDetails: false` skips the ACL and session enrichment; `allowDeleted: true` keeps an
        // archived board answerable here, because the write itself is what refuses it.
        $board = $boardService->find((int) $boardId, false, true);
        $owner = (string) $board->getOwner();
        return [
            'owner' => $owner,
            'ownerDisplayName' => $this->displayName($owner),
            'name' => (string) $board->getTitle(),
        ];
    }

    /**
     * Display name of a user, falling back to the uid when the account is gone or has none.
     *
     * @param string $userId UID whose name is shown to the caller.
     * @return string Name to put in a confirmation message.
     */
    private function displayName(string $userId): string {
        /** @var IUserManager $userManager */
        $userManager = ($this->iUserManagerProvider)();
        return $userManager->get($userId)?->getDisplayName() ?: $userId;
    }
}
