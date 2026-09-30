<?php
declare(strict_types=1);

namespace OCA\Mcp\Service;

use InvalidArgumentException;
use OCP\IConfig;

/**
 * Persisted MCP access policy: global switch, per-user admin eligibility, per-user personal
 * connection and per-user grants for (module, operation). Every read goes back to Nextcloud's
 * persisted config, so a change applies to the next request. Grants never widen Nextcloud ACLs.
 */
class GrantPolicy {
    /** Operations an admin can grant per module; Files deliberately has no delete. */
    public const CATALOG = [
        'files' => ['read', 'edit'],
        'notes' => ['read', 'create', 'edit', 'move', 'delete'],
        'deck' => ['read', 'create', 'edit', 'move', 'delete'],
        'calendar' => ['read', 'create', 'edit', 'move', 'delete', 'transfer'],
        'talk' => ['read', 'reply', 'attach', 'quote'],
    ];

    public function __construct(private IConfig $config) {}

    /** @return bool whether the administrator enabled the MCP service (off by default) */
    public function globalEnabled(): bool {
        return $this->config->getAppValue('mcp', 'enabled', '0') === '1';
    }

    /** @param bool $enabled new state of the global service switch */
    public function setGlobalEnabled(bool $enabled): void {
        $this->config->setAppValue('mcp', 'enabled', $enabled ? '1' : '0');
    }

    /**
     * @param string $uid Nextcloud user id
     * @return bool whether an administrator allowed this user to connect (off by default, admins included)
     */
    public function eligible(string $uid): bool {
        return $this->config->getUserValue($uid, 'mcp', 'eligible', '0') === '1';
    }

    /**
     * @param string $uid Nextcloud user id
     * @param bool $enabled new eligibility
     */
    public function setEligible(string $uid, bool $enabled): void {
        $this->config->setUserValue($uid, 'mcp', 'eligible', $enabled ? '1' : '0');
    }

    /**
     * @param string $uid Nextcloud user id
     * @return bool whether the user activated their own connection (off by default)
     */
    public function connected(string $uid): bool {
        return $this->config->getUserValue($uid, 'mcp', 'connected', '0') === '1';
    }

    /**
     * @param string $uid Nextcloud user id
     * @param bool $enabled new personal connection state
     */
    public function setConnected(string $uid, bool $enabled): void {
        $this->config->setUserValue($uid, 'mcp', 'connected', $enabled ? '1' : '0');
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
        return $this->config->getUserValue($uid, 'mcp', $key, $operation === 'read' ? '1' : '0') === '1';
    }

    /**
     * @param string $uid Nextcloud user id
     * @param string $module key of CATALOG
     * @param string $operation operation listed for the module in CATALOG
     * @param bool $enabled new grant
     * @throws InvalidArgumentException for a module or operation outside CATALOG
     */
    public function setGrant(string $uid, string $module, string $operation, bool $enabled): void {
        $this->config->setUserValue($uid, 'mcp', self::grantKey($module, $operation), $enabled ? '1' : '0');
    }

    /** @throws InvalidArgumentException for a module or operation outside CATALOG */
    private static function grantKey(string $module, string $operation): string {
        if (!isset(self::CATALOG[$module]) || !in_array($operation, self::CATALOG[$module], true)) {
            throw new InvalidArgumentException('Unknown module or operation');
        }
        return 'grant_' . $module . '_' . $operation;
    }
}
