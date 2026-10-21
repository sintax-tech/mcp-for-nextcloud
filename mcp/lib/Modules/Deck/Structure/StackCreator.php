<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Structure;

use OCA\Mcp\Modules\Deck\Access\DeckCaller;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Stack;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Service\PermissionService;
use OCA\Deck\Service\StackService;

class StackCreator {
    /** @param \Closure(): StackService $stackServiceProvider
     * @param \Closure(): PermissionService $permissionServiceProvider
     * @param \Closure(): StackMapper $stackMapperProvider */
    public function __construct(
        private DeckCaller $caller,
        private \Closure $stackServiceProvider,
        private \Closure $permissionServiceProvider,
        private \Closure $stackMapperProvider,
    ) {
    }

    /**
     * Creates a list on a board the caller manages.
     *
     * @param string $userId UID of the authenticated caller.
     * @param int $boardId Board that receives the list.
     * @param string $title List title.
     * @param int|null $order Position among the lists, or null to go after the last one.
     * @return Stack Created list.
     * @throws \Throwable Any Deck failure; the caller maps it with {@see DeckErrors}.
     */
    public function createStack(
        string $userId,
        int $boardId,
        string $title,
        ?int $order,
    ): Stack {
        $this->caller->bind($userId);
        /** @var StackService $stackService */
        $stackService = ($this->stackServiceProvider)();
        if ($order === null) {
            /** @var PermissionService $permissionService */
            $permissionService = ($this->permissionServiceProvider)();
            /** @var StackMapper $stackMapper */
            $stackMapper = ($this->stackMapperProvider)();
            // `StackService::create()` needs PERMISSION_MANAGE; reading the lists is an access of its own,
            // so the same check comes before the query.
            $permissionService->checkPermission(null, $boardId, Acl::PERMISSION_MANAGE);
            $highest = -1;
            foreach ($stackMapper->findAll($boardId) as $stack) {
                $highest = max($highest, (int) $stack->getOrder());
            }
            $order = $highest + 1;
        }
        // Deck v1.17.5 `create(string $title, int $boardId, int $order)`; it checks MANAGE and refuses an archived board.
        return $stackService->create($title, $boardId, $order);
    }
}
