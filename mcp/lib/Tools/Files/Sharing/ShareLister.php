<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files\Sharing;

use OCA\Mcp\Tools\Common\PathGuard;
use OCA\Mcp\Tools\ToolFailure;
use OCP\Files\Folder;

/**
 * The result of files_list_shares: the shares of one own node, or one page of every share the user created.
 * The rules live in {@see ShareAccess}, the shape of each item in {@see ShareFormatter}. Stateless.
 */
final class ShareLister {
    public function __construct(
        private ShareAccess $access,
        private ShareFormatter $formatter,
    ) {}

    /**
     * Every share the user created for a node the user owns and can see.
     *
     * @param Folder $userFolder the user's folder
     * @param string $uid authenticated user
     * @param string $path user-relative path of the node
     * @return array{path:string, shares:list<array<string, mixed>>} items as {@see ShareFormatter::item()} builds them
     * @throws \InvalidArgumentException for a path with traversal or control characters
     * @throws ToolFailure not found (also hidden), forbidden, the root folder, or a node of another owner
     */
    public function forPath(Folder $userFolder, string $uid, string $path): array {
        $node = $this->access->ownNode($userFolder, $uid, $path);
        $path = PathGuard::normalize($path);
        $shares = array_map(
            fn ($share): array => $this->formatter->item($share, $path, $this->access->isRemovable($share, $uid)),
            $this->access->sharesOf($uid, $node),
        );
        return ['path' => $path, 'shares' => $shares];
    }

    /**
     * One page of every share the user created, of any node the user can still see.
     *
     * @param Folder $userFolder the user's folder
     * @param string $uid authenticated user
     * @param int $offset listed shares to skip, 0 to {@see ShareAccess::MAX_OFFSET}
     * @return array{shares:list<array<string, mixed>>, offset:int, hasMore:bool, nextOffset:int|null} the page and where the
     *   next one starts; nextOffset is null on the last page and also past MAX_OFFSET, where only a listing by path goes on
     */
    public function mine(Folder $userFolder, string $uid, int $offset): array {
        $page = $this->access->listMine($userFolder, $uid, $offset);
        $shares = array_map(fn (array $entry): array => $this->formatter->item(
            $entry['share'],
            (string)($userFolder->getRelativePath($entry['node']->getPath()) ?? '/'),
            $this->access->isRemovable($entry['share'], $uid),
        ), $page['items']);
        $next = $offset + count($shares);
        return [
            'shares' => $shares,
            'offset' => $offset,
            'hasMore' => $page['hasMore'],
            'nextOffset' => $page['hasMore'] && $next <= ShareAccess::MAX_OFFSET ? $next : null,
        ];
    }
}
