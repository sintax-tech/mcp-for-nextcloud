<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Common;

use OCP\Constants;
use OCP\Files\IHomeStorage;
use OCP\Files\Node;
use OCP\Files\Storage\ISharedStorage;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;

/**
 * Ownership awareness of a file node: in which scope it lives (personal, shared, team folder or external
 * storage), who owns it and what the viewer may do with it. Detection reads only OCP interfaces, so no
 * app class (files_sharing, groupfolders) is referenced and an upgrade of those apps cannot break it.
 */
class NodeAccessInfo {
    /** Node lives in the viewer's own home storage. */
    public const PERSONAL = 'personal';
    /** Node reached through a share mounted in the viewer's tree. */
    public const SHARED = 'shared';
    /** Node inside a group folders (team folder) mount. */
    public const TEAM = 'team';
    /** Node inside an external storage mount. */
    public const EXTERNAL = 'external';
    /** IMountPoint::getMountType() of a group folders mount (OCA\GroupFolders\Mount\GroupMountPoint). */
    private const MOUNT_GROUP = 'group';
    /** IMountPoint::getMountType() of a share mount (apps/files_sharing/lib/SharedMount.php). */
    private const MOUNT_SHARED = 'shared';
    /** IMountPoint::getMountType() of an external storage mount. */
    private const MOUNT_EXTERNAL = 'external';

    public function __construct(
        private IUserManager $userManager,
        private ?IShareManager $shareManager = null,
    ) {}

    /**
     * Describes a node for a given viewer.
     *
     * @param Node $node node to describe
     * @param string $viewerUid authenticated user looking at the node
     * @return array{scope:string, owner:string, ownerDisplayName:string, sharedBy?:string, teamFolder?:string, permissions:array{read:bool, update:bool, create:bool, delete:bool, share:bool}}
     */
    public function describe(Node $node, string $viewerUid): array {
        $owner = $node->getOwner();
        $storage = $node->getStorage();
        $share = $this->shareOf($node, $viewerUid);
        $scope = $this->scope($node, $viewerUid, $share !== null);
        $info = [
            'scope' => $scope,
            'owner' => $owner?->getUID() ?? $viewerUid,
            'ownerDisplayName' => $owner?->getDisplayName() ?? $viewerUid,
            'permissions' => $this->permissions($node),
        ];
        if ($scope === self::TEAM) {
            $info['teamFolder'] = $this->teamFolder($node);
        }
        if ($scope === self::SHARED) {
            $info['sharedBy'] = $share === null ? $info['ownerDisplayName'] : $this->sharerName($share->getSharedBy());
        }
        return $info;
    }

        /**
     * A share mount jails the shared storage, so the object a node holds is a wrapper and
     * `instanceof ISharedStorage` never matches — instanceOfStorage() does, because it unwraps on its own.
     * When the storage really is the share the share comes from it; otherwise the share manager is asked,
     * which resolves by node and so survives any wrapping. The manager is only consulted for a node already
     * known to be shared, so listing personal files costs nothing extra.
     *
     * @param Node $node node whose storage may be a share
     * @param string $viewerUid authenticated user, for the share lookup
     * @return IShare|null the share behind the node, when there is one
     */
    private function shareOf(Node $node, string $viewerUid): ?IShare {
        $storage = $node->getStorage();
        if (!$storage->instanceOfStorage(ISharedStorage::class)) {
            return null;
        }
        if ($storage instanceof ISharedStorage) {
            return $storage->getShare();
        }
        $shares = $this->shareManager?->getSharesBy($viewerUid, IShare::TYPE_USER, $node, false, 1, 0, true) ?? [];
        return $shares === [] ? null : $shares[0];
    }

    /**
     * The mount type decides when it is one of the three known mounts; otherwise the home storage and
     * the share wrapper decide, so a node without a mount point (and a mocked one) still gets a scope.
     *
     * @param Node $node node to classify
     * @param string $viewerUid authenticated user
     * @param bool $sharedStorage whether the storage is a share wrapper
     * @return string one of the class scope constants
     */
    private function scope(Node $node, string $viewerUid, bool $sharedStorage): string {
        $mountType = $node->getMountPoint()?->getMountType();
        if (is_string($mountType) && $mountType !== '') {
            return match ($mountType) {
                self::MOUNT_GROUP => self::TEAM,
                self::MOUNT_SHARED => self::SHARED,
                self::MOUNT_EXTERNAL => self::EXTERNAL,
                default => $this->byStorage($node, $viewerUid, $sharedStorage),
            };
        }
        return $this->byStorage($node, $viewerUid, $sharedStorage);
    }

    /**
     * IShare::getSharedBy() is documented as returning the sharer's UID and not an IUser (stable33,
     * lib/public/Share/IShare.php:440, `@return string`), so the display name has to be looked up. What was
     * verified there and nothing more: a UID the user manager no longer knows falls back to the UID itself,
     * and an empty or absent UID has no name to show at all.
     *
     * @param string|null $uid sharer UID as IShare::getSharedBy() returns it
     * @return string a display name, else the UID, else a neutral word when there is no UID
     */
    private function sharerName(?string $uid): string {
        if ($uid === null || $uid === '') {
            return CommonMessages::unidentified();
        }
        return $this->userManager->get($uid)?->getDisplayName() ?? $uid;
    }

    /**
     * @param Node $node node to classify
     * @param string $viewerUid authenticated user
     * @param bool $sharedStorage whether the storage is a share wrapper
     * @return string one of the class scope constants
     */
    private function byStorage(Node $node, string $viewerUid, bool $sharedStorage): string {
        if ($node->getStorage()->instanceOfStorage(IHomeStorage::class) && $node->getOwner()?->getUID() === $viewerUid) {
            return self::PERSONAL;
        }
        return $sharedStorage || $node->isShared() ? self::SHARED : self::PERSONAL;
    }

    /**
     * Team folder name from the mount point path: OCA\GroupFolders\Folder\FolderDefinition carries no
     * name, so the last segment of the mount path is the folder as the user sees it.
     *
     * @param Node $node node inside the team folder
     * @return string team folder name, never empty
     */
    private function teamFolder(Node $node): string {
        $mount = rtrim((string)$node->getMountPoint()?->getMountPoint(), '/');
        $name = basename($mount);
        return $name === '' ? basename(dirname($node->getPath())) : $name;
    }

    /**
     * @param Node $node node to inspect
     * @return array{read:bool, update:bool, create:bool, delete:bool, share:bool} OCP permission bitmask decoded
     */
    private function permissions(Node $node): array {
        $bits = (int)$node->getPermissions();
        return [
            'read' => ($bits & Constants::PERMISSION_READ) !== 0,
            'update' => ($bits & Constants::PERMISSION_UPDATE) !== 0,
            'create' => ($bits & Constants::PERMISSION_CREATE) !== 0,
            'delete' => ($bits & Constants::PERMISSION_DELETE) !== 0,
            'share' => ($bits & Constants::PERMISSION_SHARE) !== 0,
        ];
    }
}
