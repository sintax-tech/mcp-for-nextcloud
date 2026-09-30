<?php
declare(strict_types=1);

namespace OCA\Mcp\Service;

use InvalidArgumentException;
use OCP\IConfig;

/** All reads intentionally go back to Nextcloud's persisted config on every call. */
class GrantPolicy {
    public const CATALOG = [
        'files' => ['read', 'edit'],
        'notes' => ['read', 'create', 'edit', 'move', 'delete'],
        'deck' => ['read', 'create', 'edit', 'move', 'delete'],
        'calendar' => ['read', 'create', 'edit', 'move', 'delete', 'transfer'],
        'talk' => ['read', 'reply', 'attach', 'quote'],
    ];

    public function __construct(private IConfig $config) {}

    public function globalEnabled(): bool {
        return $this->config->getAppValue('mcp', 'enabled', '0') === '1';
    }

    public function setGlobalEnabled(bool $enabled): void {
        $this->config->setAppValue('mcp', 'enabled', $enabled ? '1' : '0');
    }

    public function eligible(string $uid): bool {
        return $this->config->getUserValue($uid, 'mcp', 'eligible', '0') === '1';
    }

    public function setEligible(string $uid, bool $enabled): void {
        $this->config->setUserValue($uid, 'mcp', 'eligible', $enabled ? '1' : '0');
    }

    public function connected(string $uid): bool {
        return $this->config->getUserValue($uid, 'mcp', 'connected', '0') === '1';
    }

    public function setConnected(string $uid, bool $enabled): void {
        $this->config->setUserValue($uid, 'mcp', 'connected', $enabled ? '1' : '0');
    }

    public function canConnect(string $uid): bool {
        return $this->globalEnabled() && $this->eligible($uid) && $this->connected($uid);
    }

    public function granted(string $uid, string $module, string $operation): bool {
        $key = self::grantKey($module, $operation);
        return $this->config->getUserValue($uid, 'mcp', $key, $operation === 'read' ? '1' : '0') === '1';
    }

    public function setGrant(string $uid, string $module, string $operation, bool $enabled): void {
        $this->config->setUserValue($uid, 'mcp', self::grantKey($module, $operation), $enabled ? '1' : '0');
    }

    private static function grantKey(string $module, string $operation): string {
        if (!isset(self::CATALOG[$module]) || !in_array($operation, self::CATALOG[$module], true)) {
            throw new InvalidArgumentException('Unknown module or operation');
        }
        return 'grant_' . $module . '_' . $operation;
    }
}
