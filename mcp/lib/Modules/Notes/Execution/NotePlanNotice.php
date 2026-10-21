<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Execution;

use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\Locks\LockAwareWrite;
use OCP\Files\File;
use OCP\Files\Node;
class NotePlanNotice
{

    public function __construct(private NodeAccessInfo $accessInfo, private ?LockAwareWrite $locks = null)
    {
    }

    /**
     * @param File $note note about to be changed
     * @param string $userId authenticated user
     * @return array<string, mixed> the ownership description when the note reaches other people
     */

    public function shared(Node $note, string $userId): array
    {
        $access = $this->accessInfo->describe($note, $userId);
        return $access['scope'] === NodeAccessInfo::PERSONAL ? [] : [$access];
    }

    /**
     * @param File $note the note a plan describes
     * @param string $userId authenticated user
     * @return array<string, mixed> the lock and its warning for the plan, nothing for a free note
     */

    public function lock(File $note, string $userId): array
    {
        return $this->locks?->planNotice($note, $userId) ?? [];
    }
}
