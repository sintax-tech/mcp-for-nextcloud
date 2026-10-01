<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Common;

use OCA\Mcp\Tools\ToolFailure;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\ForbiddenException;
use OCP\Files\Node;
use OCP\Files\NotEnoughSpaceException;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Lock\LockedException;

/** Maps Nextcloud file exceptions to client-safe failures and shares node helpers between modules. */
final class NodeAccess {
    /**
     * @template T
     * @param callable():T $action file operation to run
     * @return T
     * @throws ToolFailure mapped from NotFound, NotPermitted/Forbidden, Locked and NotEnoughSpace
     */
    public static function run(callable $action): mixed {
        try {
            return $action();
        } catch (NotFoundException) {
            throw new ToolFailure(CommonMessages::notFound());
        } catch (NotPermittedException|ForbiddenException) {
            throw new ToolFailure(CommonMessages::forbidden());
        } catch (LockedException) {
            throw new ToolFailure(CommonMessages::locked());
        } catch (NotEnoughSpaceException) {
            throw new ToolFailure(CommonMessages::insufficientQuota());
        }
    }

    /**
     * @param Folder $root the user's folder
     * @param string $path user-relative path
     * @return Node readable node
     * @throws InvalidArgumentException for traversal or control characters
     * @throws \OCP\Files\NotFoundException when the node does not exist
     * @throws ToolFailure when the node is not readable
     */
    public static function get(Folder $root, string $path): Node {
        $path = PathGuard::normalize($path);
        $node = $path === '/' ? $root : $root->get(ltrim($path, '/'));
        if (!$node->isReadable()) {
            throw new ToolFailure(CommonMessages::forbidden());
        }
        return $node;
    }

    /** @return bool whether the node is a folder */
    public static function isFolder(Node $node): bool {
        return $node instanceof Folder || $node->getType() === FileInfo::TYPE_FOLDER;
    }

    /**
     * @return File the node itself
     * @throws ToolFailure when the node is a folder
     */
    public static function requireFile(Node $node): File {
        if (!$node instanceof File) {
            throw new ToolFailure(CommonMessages::notAFile());
        }
        return $node;
    }

    /**
     * Folder below $root, created segment by segment when missing.
     *
     * @param Folder $root base folder
     * @param string $relative path below $root ('' or '/' returns $root)
     * @return Folder existing or newly created folder
     * @throws InvalidArgumentException for traversal or control characters
     * @throws ToolFailure when a file already uses one of the folder names
     */
    public static function ensureFolder(Folder $root, string $relative): Folder {
        $folder = $root;
        foreach (array_filter(explode('/', PathGuard::normalize($relative))) as $segment) {
            if ($folder->nodeExists($segment)) {
                $next = $folder->get($segment);
                if (!$next instanceof Folder) {
                    throw new ToolFailure(CommonMessages::fileInTargetFolderPath());
                }
                $folder = $next;
            } else {
                $folder = $folder->newFolder($segment);
            }
        }
        return $folder;
    }

    /**
     * @param Node $node node about to change
     * @param string|null $etag ETag the caller read before, null to skip the check
     * @throws ToolFailure when the caller's etag does not match the current one
     */
    public static function checkEtag(Node $node, ?string $etag): void {
        if ($etag !== null && trim($etag, '"') !== trim((string)$node->getEtag(), '"')) {
            throw new ToolFailure(CommonMessages::conflict());
        }
    }
}
