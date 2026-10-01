<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files\Sharing;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\VisibilityGuard;
use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\Common\PathGuard;
use OCA\Mcp\Tools\Files\FilesMessages;
use OCA\Mcp\Tools\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;

/**
 * The access rules every sharing tool shares: which node may be shared, which shares the user sees, which of
 * them the user may remove and which grant each share type needs.
 *
 * Rules (plan 0.10, decisions 2, 3 and 4):
 * - only a node the user owns (`getOwner()->getUID() === uid`) and that the VisibilityGuard shows; a hidden
 *   node is refused exactly like a missing one;
 * - only the shares the user initiated (`getSharesBy(..., reshares: false)`), so nobody else's shares are
 *   enumerated, not even other people's shares of the user's own file;
 * - removable only when the user created it, owns the file and it is not a Talk attachment (TYPE_ROOM);
 * - USER and GROUP need `files.share`, LINK needs `files.link`.
 *
 * Everything goes through IShareManager, so the administrator's sharing rules always apply. Stateless: the
 * user is a parameter of every call and one instance serves every request.
 */
final class ShareAccess {
    /** Shares per page of {@see self::listMine()}. */
    public const PAGE_SIZE = 50;
    /** Highest offset {@see self::listMine()} accepts: each page reads up to offset + PAGE_SIZE + 1 shares per type. */
    public const MAX_OFFSET = 1000;
    /** Share types listed, in the order a listing presents them. */
    public const LISTED_TYPES = [IShare::TYPE_USER, IShare::TYPE_GROUP, IShare::TYPE_LINK, IShare::TYPE_ROOM];
    /** Grant operation of `files` each manageable type needs. */
    private const OPERATIONS = [IShare::TYPE_USER => 'share', IShare::TYPE_GROUP => 'share', IShare::TYPE_LINK => 'link'];

    public function __construct(
        private IShareManager $shareManager,
        private GrantPolicy $policy,
        private ?VisibilityGuard $visibilityGuard = null,
    ) {}

    /**
     * The node at a path, when the user may share it: it exists, is visible, is readable, is not the user
     * folder itself and belongs to the user.
     *
     * @param Folder $userFolder the user's folder
     * @param string $uid authenticated user
     * @param string $path user-relative path
     * @return Node the node to share
     * @throws \InvalidArgumentException for a path with traversal or control characters
     * @throws ToolFailure not found (also for a hidden node), forbidden, the root folder, or a node of another owner
     */
    public function ownNode(Folder $userFolder, string $uid, string $path): Node {
        if (PathGuard::normalize($path) === '/') {
            throw new ToolFailure(FilesMessages::shareRootRefused());
        }
        $node = NodeAccess::run(fn (): Node => NodeAccess::get($userFolder, $path, $this->visibilityGuard));
        if ($node->getOwner()?->getUID() !== $uid) {
            throw new ToolFailure(FilesMessages::shareNotOwner());
        }
        return $node;
    }

    /**
     * Every share the user created for one node, in {@see self::LISTED_TYPES} order.
     *
     * @param string $uid authenticated user, the initiator of the shares returned
     * @param Node $node node already checked by {@see self::ownNode()}
     * @return list<IShare> the shares, none of them created by somebody else
     */
    public function sharesOf(string $uid, Node $node): array {
        $shares = [];
        foreach (self::LISTED_TYPES as $type) {
            array_push($shares, ...$this->sharesBy($uid, $type, $node, -1, 0));
        }
        return $shares;
    }

    /**
     * The share the user already has for this node and recipient, which a new share should update instead of
     * duplicating (decision 6). Any link of the user counts for `link`.
     *
     * @param string $uid authenticated user
     * @param Node $node node already checked by {@see self::ownNode()}
     * @param ShareRecipient $recipient who the share would be for
     * @return IShare|null the existing share, null when there is none
     */
    public function findExisting(string $uid, Node $node, ShareRecipient $recipient): ?IShare {
        foreach ($this->sharesBy($uid, $recipient->shareType(), $node, -1, 0) as $share) {
            if ($recipient->matches($share)) {
                return $share;
            }
        }
        return null;
    }

    /**
     * One page of every share the user created, all types concatenated in {@see self::LISTED_TYPES} order.
     *
     * The offset counts listed shares only: a share whose node is hidden or out of the user's reach is skipped
     * before paging, so it neither appears nor shortens a page. The core counts nothing per type, so each type
     * is read from its start up to what the page needs; MAX_OFFSET bounds that cost.
     *
     * @param Folder $userFolder the user's folder, for the user's own view of each node
     * @param string $uid authenticated user, the initiator of every share returned
     * @param int $offset listed shares to skip, 0 to MAX_OFFSET (the tool schema enforces it)
     * @param int $limit shares on the page, at most PAGE_SIZE
     * @return array{items: list<array{share: IShare, node: Node}>, hasMore: bool} the page, each share with its node as the user sees it
     */
    public function listMine(Folder $userFolder, string $uid, int $offset, int $limit = self::PAGE_SIZE): array {
        $offset = max(0, min($offset, self::MAX_OFFSET));
        $limit = max(1, min($limit, self::PAGE_SIZE));
        $need = $offset + $limit + 1;
        $found = [];
        foreach (self::LISTED_TYPES as $type) {
            for ($from = 0; count($found) < $need; $from += $need) {
                $batch = $this->sharesBy($uid, $type, null, $need, $from);
                foreach ($batch as $share) {
                    $node = $this->nodeOf($userFolder, $share);
                    if ($node !== null && count($found) < $need) {
                        $found[] = ['share' => $share, 'node' => $node];
                    }
                }
                if (count($batch) < $need) {
                    break;
                }
            }
        }
        return ['items' => array_slice($found, $offset, $limit), 'hasMore' => count($found) > $offset + $limit];
    }

    /**
     * The shared node as the user sees it in their own folder, so a path never reveals the owner's tree.
     *
     * @param Folder $userFolder the user's folder
     * @param IShare $share share of the user
     * @return Node|null the node, null when it is gone, out of the user's folder or hidden by the VisibilityGuard
     */
    public function nodeOf(Folder $userFolder, IShare $share): ?Node {
        try {
            $node = $userFolder->getFirstNodeById($share->getNodeId());
        } catch (NotFoundException) {
            return null;
        }
        if ($node === null || ($this->visibilityGuard !== null && !$this->visibilityGuard->isVisible($node))) {
            return null;
        }
        return $node;
    }

    /**
     * Whether the user may remove this share (decision 3): created by the user, of a file the user owns, of a
     * type this app manages. A Talk attachment (TYPE_ROOM) never is: removing it would erase it from the chat
     * of everybody else, so it is removed in Talk. The owner is the one the share records, which the core
     * keeps in step with ownership transfers.
     *
     * @param IShare $share share listed for the user
     * @param string $uid authenticated user
     * @return bool true when files_unshare may remove it
     */
    public function isRemovable(IShare $share, string $uid): bool {
        return isset(self::OPERATIONS[$share->getShareType()])
            && $share->getSharedBy() === $uid
            && $share->getShareOwner() === $uid;
    }

    /**
     * @param int $shareType IShare::TYPE_* of the share to create, change or remove
     * @return string the `files` grant operation it needs: `share` for USER and GROUP, `link` for LINK
     * @throws ToolFailure for any other type, a Talk attachment included
     */
    public static function operationFor(int $shareType): string {
        return self::OPERATIONS[$shareType] ?? throw new ToolFailure(FilesMessages::shareTypeUnsupported());
    }

    /**
     * Refuses a share type the administrator did not grant to this user. The tool grant only lets the call
     * in; this is the per-type check that has to run on the plan and again on the confirmed call.
     *
     * @param string $uid authenticated user
     * @param int $shareType IShare::TYPE_* about to be created, changed or removed
     * @throws ToolFailure when the grant is missing or the type is not manageable
     */
    public function assertGranted(string $uid, int $shareType): void {
        if (!$this->policy->granted($uid, 'files', self::operationFor($shareType))) {
            throw new ToolFailure(FilesMessages::shareNotGranted());
        }
    }

    /**
     * The shares the user initiated, of one type. A missing room provider (Talk disabled) means no room
     * shares rather than an error; every other type always has its provider in the core.
     *
     * @param string $uid initiator
     * @param int $type IShare::TYPE_*
     * @param Node|null $node node to restrict to, null for every node
     * @param int $limit maximum shares, -1 for all
     * @param int $offset shares to skip
     * @return list<IShare>
     */
    private function sharesBy(string $uid, int $type, ?Node $node, int $limit, int $offset): array {
        try {
            return array_values($this->shareManager->getSharesBy($uid, $type, $node, false, $limit, $offset));
        } catch (\Exception $e) {
            if ($type === IShare::TYPE_ROOM) {
                return [];
            }
            throw $e;
        }
    }
}
