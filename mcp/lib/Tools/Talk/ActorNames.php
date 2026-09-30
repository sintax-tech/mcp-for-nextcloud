<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

use OCP\IUserManager;

/**
 * Best-effort name for a chat actor, in one place so a message read and the draft quoting it agree: real users
 * are resolved through the account backend, and anything else (guest, federated actor, external contact) keeps
 * its raw identifier, which is what Talk shows as well.
 */
class ActorNames {
    public function __construct(private IUserManager $userManager) {}

    /**
     * @param string $actorId Actor identifier as Talk stores it
     * @return string The display name, or the raw identifier when the account cannot be resolved
     */
    public function displayName(string $actorId): string {
        return $this->userManager->get($actorId)?->getDisplayName() ?? $actorId;
    }
}