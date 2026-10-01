<?php
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;

/**
 * Resolves an `Authorization: Bearer` access token issued by this app into its user: the hash must exist, the token
 * must not be expired, its audience must be this MCP server and the owner must pass the TokenOwnerGate.
 */
class AccessTokenAuthenticator {
    public function __construct(
        private OAuthStore $store,
        private TokenHasher $hasher,
        private ITimeFactory $time,
        private TokenOwnerGate $gate,
    ) {}

    /** @return bool true when the header carries a token issued by this app */
    public static function isOwnBearer(string $authorization): bool {
        return str_starts_with($authorization, 'Bearer ncmcp_at_');
    }

    /**
     * @param string $authorization raw Authorization header
     * @param string $resource resource URL of the current request
     * @return IUser|null the token owner, or null when the token is unknown, expired, for another audience or revoked
     */
    public function authenticate(string $authorization, string $resource): ?IUser {
        if (!self::isOwnBearer($authorization)) {
            return null;
        }
        $row = $this->store->findByAccess($this->hasher->hash(substr($authorization, 7)));
        if ($row === null || !in_array('mcp', explode(' ', (string)$row['scope']), true)
            || (int)$row['access_expires'] < $this->time->getTime()
            || !ResourceUrl::sameResource((string)$row['resource'], $resource)) {
            return null;
        }
        return $this->gate->owner((string)$row['user_id']);
    }
}
