<?php
declare(strict_types=1);

namespace OCA\Mcp\Service\Compat;

use OCP\IUser;

/**
 * Whether MCP may use an app, on every supported Nextcloud: the app is enabled and, for the optional apps whose
 * internal classes MCP calls, its version is not older than the oldest release the API contract was checked against.
 *
 * - `IAppManager::isEnabledForAnyone()` only exists from Nextcloud 32 on; Nextcloud 31 answers the same question with
 *   `isInstalled()`, which despite the name checks that the app is enabled, for everyone or for some groups
 *   (lib/public/App/IAppManager.php of v31.0.0). Nextcloud 32 deprecates `isInstalled()` in favour of the new method,
 *   so the new one is asked whenever it exists.
 * - An app older than its minimum counts as off, exactly as an app that is not installed: the tools of its module
 *   leave tools/list, a call is refused, and the admin page says why ({@see self::unsupported()}).
 */
final class AppEnablement {
    /**
     * Oldest release of each optional app MCP calls into, per app id. Talk 21.1.4 is the first Nextcloud 31 release
     * whose `ChatManager::addSystemMessage()` takes the participant as its second argument; Deck 1.15.0 is the first
     * release for Nextcloud 31. tests/Unit/Contract checks the APIs used against these releases.
     */
    public const MINIMUM_VERSIONS = ['deck' => '1.15.0', 'spreed' => '21.1.4'];

    /**
     * @param object $apps the server's IAppManager, of whichever supported Nextcloud is running
     * @param string $appId app id, such as "deck" or "spreed"
     * @param IUser|null $user the user, as IAppManager::isEnabledForUser() takes it
     * @return bool whether the app is enabled for the user in a version MCP supports
     */
    public static function forUser(object $apps, string $appId, ?IUser $user): bool {
        return $apps->isEnabledForUser($appId, $user) && self::supported($apps, $appId);
    }

    /**
     * @param object $apps the server's IAppManager, of whichever supported Nextcloud is running
     * @param string $appId app id, such as "deck" or "spreed"
     * @return bool whether the app is enabled for at least one user, in a version MCP supports
     */
    public static function forAnyone(object $apps, string $appId): bool {
        return self::enabledForAnyone($apps, $appId) && self::supported($apps, $appId);
    }

    /**
     * @param object $apps the server's IAppManager, of whichever supported Nextcloud is running
     * @return list<array{app:string, installed:string, required:string}> enabled apps older than their minimum
     */
    public static function unsupported(object $apps): array {
        $found = [];
        foreach (self::MINIMUM_VERSIONS as $appId => $required) {
            if (self::enabledForAnyone($apps, $appId) && !self::supported($apps, $appId)) {
                $found[] = ['app' => $appId, 'installed' => (string)$apps->getAppVersion($appId), 'required' => $required];
            }
        }
        return $found;
    }

    /**
     * @param object $apps the server's IAppManager
     * @param string $appId app id
     * @return bool whether the app is enabled for at least one user, whatever its version
     */
    private static function enabledForAnyone(object $apps, string $appId): bool {
        if (method_exists($apps, 'isEnabledForAnyone')) {
            return (bool)$apps->isEnabledForAnyone($appId);
        }
        return (bool)$apps->isInstalled($appId);
    }

    /**
     * Only a version known to be too old is refused: an empty answer means the server could not tell, and an app
     * without a minimum is never asked.
     *
     * @param object $apps the server's IAppManager
     * @param string $appId app id
     * @return bool whether the installed version is at least the minimum, when the app has one
     */
    private static function supported(object $apps, string $appId): bool {
        $required = self::MINIMUM_VERSIONS[$appId] ?? null;
        if ($required === null) {
            return true;
        }
        $installed = (string)$apps->getAppVersion($appId);
        return $installed === '' || version_compare($installed, $required, '>=');
    }
}
