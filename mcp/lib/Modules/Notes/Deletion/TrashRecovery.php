<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Deletion;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\IUserManager;
class TrashRecovery
{

    public function __construct(private IUserManager $userManager, private IAppManager $appManager)
    {
    }
    public const TRASH_STORAGE = 'OCA\Files_Trashbin\Storage';

    /**
     * A deletion only happens when the trash bin would take the note back.
     *
     * @param string $userId authenticated user
     * @throws ToolFailure when files_trashbin is off for this account
     */

    public function assertEnabled(string $userId): void
    {
        $user = $this->userManager->get($userId);
        if ($user === null || !$this->appManager->isEnabledForUser('files_trashbin', $user)) {
            throw new ToolFailure(\OCA\Mcp\Modules\Notes\Execution\WriteMessages::notRecoverable());
        }
    }

    public function assertStorage(File $note): void
    {
        if (!$note->getStorage()->instanceOfStorage(self::TRASH_STORAGE)) {
            throw new ToolFailure(\OCA\Mcp\Modules\Notes\Execution\WriteMessages::notRecoverable());
        }
    }
}
