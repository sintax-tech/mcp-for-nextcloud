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
     * @param callable():T $action
     * @return T
     */
    public static function run(callable $action): mixed {
        try {
            return $action();
        } catch (NotFoundException) {
            throw new ToolFailure(ToolFailure::NOT_FOUND);
        } catch (NotPermittedException|ForbiddenException) {
            throw new ToolFailure(ToolFailure::FORBIDDEN);
        } catch (LockedException) {
            throw new ToolFailure(ToolFailure::LOCKED);
        } catch (NotEnoughSpaceException) {
            throw new ToolFailure('Espaço insuficiente na cota do usuário.');
        }
    }

    public static function get(Folder $root, string $path): Node {
        $path = PathGuard::normalize($path);
        $node = $path === '/' ? $root : $root->get(ltrim($path, '/'));
        if (!$node->isReadable()) {
            throw new ToolFailure(ToolFailure::FORBIDDEN);
        }
        return $node;
    }

    public static function isFolder(Node $node): bool {
        return $node instanceof Folder || $node->getType() === FileInfo::TYPE_FOLDER;
    }

    public static function requireFile(Node $node): File {
        if (!$node instanceof File) {
            throw new ToolFailure('O caminho informado não é um arquivo.');
        }
        return $node;
    }

    /** Folder below $root, created segment by segment when missing. */
    public static function ensureFolder(Folder $root, string $relative): Folder {
        $folder = $root;
        foreach (array_filter(explode('/', PathGuard::normalize($relative))) as $segment) {
            if ($folder->nodeExists($segment)) {
                $next = $folder->get($segment);
                if (!$next instanceof Folder) {
                    throw new ToolFailure('Já existe um arquivo com o nome da pasta de destino.');
                }
                $folder = $next;
            } else {
                $folder = $folder->newFolder($segment);
            }
        }
        return $folder;
    }

    /** @throws ToolFailure when the caller's etag does not match the current one */
    public static function checkEtag(Node $node, ?string $etag): void {
        if ($etag !== null && trim($etag, '"') !== trim((string)$node->getEtag(), '"')) {
            throw new ToolFailure(ToolFailure::CONFLICT);
        }
    }
}
