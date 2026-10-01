<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

/**
 * What a move can honestly claim afterwards.
 *
 * The id is read back from the node, so idPreserved is a measurement and not a promise. Versions and shares
 * are counted through the apps that own them, and reported as null when the app is off — an absent app is
 * not the same as "zero versions", and saying zero would be a claim nobody checked.
 *
 * These are counts taken after the fact. They describe what happened; they do not guarantee it will keep
 * happening on a different storage, which is why files_move refuses to cross one.
 */
final class MoveReport {
    /** Nextcloud app that owns the versions, resolved lazily like every other files_versions call. */
    private const VERSIONS_MANAGER = 'OCA\Files_Versions\Versions\IVersionManager';

    public function __construct(
        private \Psr\Container\ContainerInterface $container,
        private \OCP\Share\IManager $shareManager,
        private \OCP\App\IAppManager $appManager,
    ) {}

    /**
     * @param \OCP\Files\File $file file whose versions are counted
     * @param \OCP\IUser $user owner the versions belong to
     * @return int|null the number of stored versions, or null when versioning is off or unreachable
     */
    public function versions(\OCP\Files\File $file, \OCP\IUser $user): ?int {
        if ($user === null || !$this->appManager->isEnabledForUser('files_versions', $user)) {
            return null;
        }
        try {
            $manager = $this->container->get(self::VERSIONS_MANAGER);
        } catch (\Throwable) {
            return null;
        }
        return is_object($manager) ? count($manager->getVersionsForFile($user, $file)) : null;
    }

    /**
     * @param \OCP\Files\Node $node the node whose shares are counted; a folder can be shared too
     * @param string $userId the viewer, whose user shares are the ones in question
     * @return int|null how many user shares point at the node, or null when it cannot be resolved
     */
    public function shares(\OCP\Files\Node $node, string $userId): ?int {
        try {
            return count($this->shareManager->getSharesBy($userId, \OCP\Share\IShare::TYPE_USER, $node, false, 100, 0, true));
        } catch (\Throwable) {
            return null;
        }
    }
}
