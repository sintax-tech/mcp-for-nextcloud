<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Talk;

use InvalidArgumentException;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Throwable;

/**
 * Resolves the file a user wants to attach, always inside that user's own folder.
 *
 * The path is checked before any filesystem access, so a traversal attempt never reaches storage, and a node the
 * user cannot share is reported exactly like a node that does not exist: the module must not reveal paths.
 */
class UserFileResolver {
    public function __construct(
        private IRootFolder $rootFolder,
    ) {}

    /**
     * The shareable file at the given path, relative to the user's own folder.
     *
     * @param string $userId Authenticated user, the only user whose files are reachable
     * @param string $path Path relative to the user folder, with or without a leading slash
     * @return File The file, guaranteed to be a file the user may read and share
     * @throws InvalidArgumentException When the path is empty, malformed or tries to escape the user folder
     * @throws FileAccessException When the file is missing, is a folder or cannot be shared
     */
    public function resolveShareableFile(string $userId, string $path): File {
        $cleanPath = $this->assertUsablePath($path);

        try {
            $node = $this->rootFolder->getUserFolder($userId)->get($cleanPath);
        } catch (Throwable $e) {
            // A missing node, a denied one and a broken backend are the same answer to the caller.
            throw new FileAccessException(Messages::FILE_NOT_FOUND, $e);
        }

        if (!$node instanceof File
            || !$node->isReadable()
            || ($node->getPermissions() & Constants::PERMISSION_SHARE) !== Constants::PERMISSION_SHARE) {
            throw new FileAccessException(Messages::FILE_NOT_FOUND);
        }

        return $node;
    }

    /**
     * Normalizes the path and refuses anything that is not a plain relative path inside the user folder.
     *
     * @param string $path Path as received from the client
     * @return string The path without leading, trailing or duplicated slashes
     * @throws InvalidArgumentException When the path is empty, has a null byte, a backslash or a relative segment
     */
    private function assertUsablePath(string $path): string {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
            throw new InvalidArgumentException(Messages::INVALID_PATH);
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '') {
                continue;
            }
            if ($segment === '.' || $segment === '..') {
                throw new InvalidArgumentException(Messages::INVALID_PATH);
            }
            $segments[] = $segment;
        }

        if ($segments === []) {
            throw new InvalidArgumentException(Messages::INVALID_PATH);
        }

        return implode('/', $segments);
    }
}
