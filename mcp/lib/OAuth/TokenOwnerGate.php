<?php
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

use OCA\Mcp\Service\GrantPolicy;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Decides whether a token owner may still use OAuth tokens: the account exists and is enabled and the MCP
 * connection policy (global switch, admin eligibility, personal connection) allows it. When not, every token of
 * that user is deleted, so re-enabling later requires a new consent.
 */
class TokenOwnerGate {
    public function __construct(
        private IUserManager $userManager,
        private GrantPolicy $policy,
        private OAuthStore $store,
    ) {}

    /** @return IUser|null the usable owner, or null after revoking all of the user's tokens */
    public function owner(string $uid): ?IUser {
        $user = $this->userManager->get($uid);
        if ($user !== null && $user->isEnabled() && $this->policy->canConnect($uid)) {
            return $user;
        }
        $this->store->deleteForUser($uid);
        return null;
    }
}
