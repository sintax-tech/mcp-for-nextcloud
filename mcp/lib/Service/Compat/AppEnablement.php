<?php
declare(strict_types=1);

namespace OCA\Mcp\Service\Compat;

/**
 * Whether an app is enabled for anyone on the server, on every supported Nextcloud.
 *
 * `IAppManager::isEnabledForAnyone()` only exists from Nextcloud 32 on; Nextcloud 31 answers the same question with
 * `isInstalled()`, which despite the name checks that the app is enabled, for everyone or for some groups
 * (lib/public/App/IAppManager.php of v31.0.0). Nextcloud 32 deprecates `isInstalled()` in favour of the new method,
 * so the new one is asked whenever it exists.
 */
final class AppEnablement {
    /**
     * @param object $apps the server's IAppManager, of whichever supported Nextcloud is running
     * @param string $appId app id, such as "deck" or "spreed"
     * @return bool whether the app is enabled for at least one user
     */
    public static function forAnyone(object $apps, string $appId): bool {
        if (method_exists($apps, 'isEnabledForAnyone')) {
            return (bool)$apps->isEnabledForAnyone($appId);
        }
        return (bool)$apps->isInstalled($appId);
    }
}
