<?php
declare(strict_types=1);

namespace OCA\Mcp\Service;

use InvalidArgumentException;
use OCP\Config\IUserConfig;
use OCP\IConfig;
use OCA\Mcp\OAuth\OAuthStore;

/**
 * Persisted MCP access policy: global switch, per-user admin eligibility, per-user personal
 * connection and per-user grants for (module, operation). Every read goes back to Nextcloud's
 * persisted config, so a change applies to the next request. Grants never widen Nextcloud ACLs.
 */
class GrantPolicy {
    /** Operations an admin can grant per module; Files deliberately has no delete. */
    public const CATALOG = [
        // 'restore' rolls a file back to a stored version and 'create' adds a folder or a copy;
        // like every operation but read, both start denied.
        'files' => ['read', 'edit', 'create', 'move', 'restore'],
        'notes' => ['read', 'create', 'edit', 'move', 'delete'],
        'deck' => ['read', 'create', 'edit', 'move', 'delete'],
        'contacts' => ['read', 'create', 'edit', 'delete'],
        'tasks' => ['read', 'create', 'edit', 'delete'],
        'calendar' => ['read', 'create', 'edit', 'move', 'delete', 'transfer'],
        // 'create' is what talk_create_group asks for: opening a conversation invites people, so it is denied by
        // default like every other operation that is not a read.
        // Account search; read only, so the model can find who to invite or message.
        'people' => ['read'],
        'talk' => ['read', 'reply', 'attach', 'quote', 'create'],
    ];

    /** App id under which every value of this policy is stored. */
    public const APP = 'mcp';
    /** App config key of the global service switch; never core's reserved 'enabled'. */
    public const SERVICE_KEY = 'service_enabled';
    /** User config key of the admin eligibility. */
    public const ELIGIBLE_KEY = 'eligible';
    /** User config key of the personal connection. */
    public const CONNECTED_KEY = 'connected';
    /**
     * App config keys core stable33 owns for every app (AppManager, OC_App, AppConfig lexicon bypass)
     * plus legacy ones; this app must never read or write them.
     */
    public const RESERVED_APP_KEYS = ['enabled', 'installed_version', 'types', 'levels', 'ocsid'];

    public function __construct(private IConfig $config, private OAuthStore $oauth, private IUserConfig $userConfig) {}

    /** @return bool whether the administrator enabled the MCP service (off by default) */
    public function globalEnabled(): bool {
        return $this->config->getAppValue(self::APP, self::SERVICE_KEY, '0') === '1';
    }

    /** @param bool $enabled new state of the global service switch */
    public function setGlobalEnabled(bool $enabled): void {
        $this->config->setAppValue(self::APP, self::SERVICE_KEY, $enabled ? '1' : '0');
        if (!$enabled) {
            $this->oauth->deleteAll();
        }
    }

    /**
     * @param string $uid Nextcloud user id
     * @return bool whether an administrator allowed this user to connect (off by default, admins included)
     */
    public function eligible(string $uid): bool {
        return $this->config->getUserValue($uid, self::APP, self::ELIGIBLE_KEY, '0') === '1';
    }

    /**
     * @param string $uid Nextcloud user id
     * @param bool $enabled new eligibility
     */
    public function setEligible(string $uid, bool $enabled): void {
        $this->config->setUserValue($uid, self::APP, self::ELIGIBLE_KEY, $enabled ? '1' : '0');
        if (!$enabled) {
            $this->oauth->deleteForUser($uid);
        }
    }

    /**
     * @param string $uid Nextcloud user id
     * @return bool whether the user activated their own connection (off by default)
     */
    public function connected(string $uid): bool {
        return $this->config->getUserValue($uid, self::APP, self::CONNECTED_KEY, '0') === '1';
    }

    /**
     * @param string $uid Nextcloud user id
     * @param bool $enabled new personal connection state
     */
    public function setConnected(string $uid, bool $enabled): void {
        $this->config->setUserValue($uid, self::APP, self::CONNECTED_KEY, $enabled ? '1' : '0');
    }

    /**
     * Users whose eligibility or connection flag is on, for the admin filters and the status summary.
     *
     * @param string $key ELIGIBLE_KEY or CONNECTED_KEY
     * @return list<string> uids with that flag set to '1', sorted
     * @throws InvalidArgumentException for any other key
     */
    public function flaggedUsers(string $key): array {
        if (!in_array($key, [self::ELIGIBLE_KEY, self::CONNECTED_KEY], true)) {
            throw new InvalidArgumentException('Invalid request');
        }
        $uids = array_map('strval', iterator_to_array($this->userConfig->searchUsersByValueString(self::APP, $key, '1'), false));
        sort($uids);
        return array_values(array_unique($uids));
    }

    /**
     * Users an administrator allowed and who activated their connection: the one meaning of "connected" in the
     * status summary and in the matrix filter.
     *
     * @return list<string> uids with both flags on, sorted
     */
    public function connectedUsers(): array {
        return array_values(array_intersect($this->flaggedUsers(self::ELIGIBLE_KEY), $this->flaggedUsers(self::CONNECTED_KEY)));
    }

    /**
     * @param string $uid Nextcloud user id
     * @return bool true only when the service is on, the user is eligible and connected
     */
    public function canConnect(string $uid): bool {
        return $this->globalEnabled() && $this->eligible($uid) && $this->connected($uid);
    }

    /**
     * @param string $uid Nextcloud user id
     * @param string $module key of CATALOG
     * @param string $operation operation listed for the module in CATALOG
     * @return bool the stored grant; read defaults to allowed, every other operation to denied
     * @throws InvalidArgumentException for a module or operation outside CATALOG
     */
    public function granted(string $uid, string $module, string $operation): bool {
        $key = self::grantKey($module, $operation);
        return $this->config->getUserValue($uid, self::APP, $key, $operation === 'read' ? '1' : '0') === '1';
    }

    /**
     * @param string $uid Nextcloud user id
     * @param string $module key of CATALOG
     * @param string $operation operation listed for the module in CATALOG
     * @param bool $enabled new grant
     * @throws InvalidArgumentException for a module or operation outside CATALOG
     */
    public function setGrant(string $uid, string $module, string $operation, bool $enabled): void {
        $this->config->setUserValue($uid, self::APP, self::grantKey($module, $operation), $enabled ? '1' : '0');
    }

    /**
     * Reads eligibility, connection and every grant of many users with one query per key
     * (IConfig::getUserValueForUsers, backed by IUserConfig::getValuesByUsers in stable33),
     * so the cost does not grow with the number of users.
     *
     * @param list<string> $uids Nextcloud user ids
     * @return array<string, array{eligible:bool, connected:bool, grants:array<string, array<string, bool>>}> state by uid, defaults applied
     */
    public function forUsers(array $uids): array {
        $uids = array_values(array_unique($uids));
        if ($uids === []) {
            return [];
        }
        $load = fn (string $key): array => $this->config->getUserValueForUsers(self::APP, $key, $uids);
        $eligible = $load(self::ELIGIBLE_KEY);
        $connected = $load(self::CONNECTED_KEY);
        $grants = [];
        foreach (self::CATALOG as $module => $operations) {
            foreach ($operations as $operation) {
                $grants[$module][$operation] = $load(self::grantKey($module, $operation));
            }
        }
        $out = [];
        foreach ($uids as $uid) {
            $state = ['eligible' => ($eligible[$uid] ?? '0') === '1', 'connected' => ($connected[$uid] ?? '0') === '1', 'grants' => []];
            foreach (self::CATALOG as $module => $operations) {
                foreach ($operations as $operation) {
                    $state['grants'][$module][$operation] = ($grants[$module][$operation][$uid] ?? ($operation === 'read' ? '1' : '0')) === '1';
                }
            }
            $out[$uid] = $state;
        }
        return $out;
    }

    /**
     * @param string $module candidate module
     * @param string $operation candidate operation
     * @return bool whether the pair exists in CATALOG
     */
    public static function inCatalog(string $module, string $operation): bool {
        return isset(self::CATALOG[$module]) && in_array($operation, self::CATALOG[$module], true);
    }

    /** @throws InvalidArgumentException for a module or operation outside CATALOG */
    private static function grantKey(string $module, string $operation): string {
        if (!self::inCatalog($module, $operation)) {
            throw new InvalidArgumentException('Unknown module or operation');
        }
        return 'grant_' . $module . '_' . $operation;
    }
}
