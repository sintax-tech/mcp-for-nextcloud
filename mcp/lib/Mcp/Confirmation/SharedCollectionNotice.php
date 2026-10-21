<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Mcp\Confirmation;

use OCA\Mcp\Platform\Dav\Collections\Collection;
use OCP\IUserManager;
final class SharedCollectionNotice
{
    public function __construct(private IUserManager $users)
    {
    }
    public function forCollection(Collection $calendar, string $userId): ?array
    {
        if (!$calendar->ownedByOther($userId)) {
            return null;
        }
        $displayName = $this->users->get($calendar->ownerId)?->getDisplayName() ?: $calendar->ownerId;
        return [
            'requiresConfirmation' => true,
            'scope' => 'shared',
            'owner' => $calendar->ownerId,
            'ownerDisplayName' => $displayName,
            'resource' => $calendar->name,
            'message' => SharedCollectionMessages::sharedConfirmation($calendar->name, $displayName),
        ];
    }
}
