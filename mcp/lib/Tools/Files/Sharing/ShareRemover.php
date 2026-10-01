<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files\Sharing;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\Common\PathGuard;
use OCA\Mcp\Tools\Files\FilesMessages;
use OCA\Mcp\Tools\PlanChanged;
use OCA\Mcp\Tools\PlanState;
use OCA\Mcp\Tools\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * files_unshare: removes one share the user created of a file the user owns (plan 0.10, decisions 3 and 8, invariant 5).
 * The share is named by the `shareId` files_list_shares returns, or by `path` plus `with` (`user:`, `group:`, `link`).
 *
 * {@see self::plan()} and {@see self::apply()} run the same {@see self::prepare()}, so the confirmed call checks
 * everything again, and removes only the share the plan named, as the plan showed it: the plan carries its
 * {@see PlanState}, and a confirmed call that finds the share changed, or another share for the same recipient,
 * removes nothing and answers with the new plan ({@see PlanChanged}). What is not the user's own answers exactly like what does not exist: a share of somebody else,
 * a share of a file somebody else owns, a type this app does not manage, an unknown id and a hidden node are all
 * "not found". The only exception is the user's own Talk attachment, which gets the Talk message because the
 * person already knows it exists. The grant of the removed type is checked last, so a missing grant cannot reveal a
 * share either. Only the share goes: no file or folder is ever touched. Stateless: the user is a parameter of every call.
 */
final class ShareRemover {
    public function __construct(
        private ShareAccess $access,
        private ShareRecipientResolver $recipients,
        private IShareManager $shareManager,
        private LoggerInterface $logger,
        private PlanState $states,
    ) {}

    /**
     * The plan of files_unshare: who loses access to what. Writes nothing.
     *
     * @param Folder $userFolder the user's folder
     * @param string $uid authenticated user
     * @param array{shareId?:string, path?:string, with?:string} $arguments validated arguments
     * @return array{shareId:string, path:string, isDir:bool, with:array{type:string, id:string, displayName:string}, message:string, plan_state:string}
     *   `plan_state` is what the confirmed call gives back ({@see PlanState})
     * @throws ArgumentValidationException when the share is not named by an id or by a path with a recipient, or for an unknown recipient
     * @throws ToolFailure not found, a Talk attachment, or a type the administrator did not grant
     */
    public function plan(Folder $userFolder, string $uid, array $arguments): array {
        return $this->planOf($this->prepare($userFolder, $uid, $arguments), $uid);
    }

    /**
     * @param array{share:IShare, node:Node, path:string, folder:bool, recipient:ShareRecipient} $target what {@see self::prepare()} returned
     * @param string $uid authenticated user, part of the state
     * @param bool $changed whether the confirmed call found this share instead of the approved one: a warning says so
     * @return array<string, mixed> the plan, in the shape {@see self::plan()} documents, plus `warnings` when changed
     */
    private function planOf(array $target, string $uid, bool $changed = false): array {
        return [
            'shareId' => (string)$target['share']->getFullId(),
            'path' => $target['path'],
            'isDir' => $target['folder'],
            'with' => $target['recipient']->toArray(),
            'message' => CommonMessages::planNothingChanged(),
            PlanState::ARGUMENT => $this->stateOf($target, $uid),
        ] + ($changed ? ['warnings' => [['message' => FilesMessages::sharePlanChanged()]]] : []);
    }

    /**
     * @param array{share:IShare, node:Node, path:string, recipient:ShareRecipient} $target what {@see self::prepare()} returned
     * @param string $uid authenticated user, so the value never confirms for another account
     * @return string the opaque {@see PlanState} of the share to remove: its id, type, node (id and path), recipient
     *   (kind and id) and its fields as they are now, everything that names what the removal takes away
     */
    private function stateOf(array $target, string $uid): string {
        $share = $target['share'];
        return $this->states->of('files_unshare', $uid, [
            'action' => 'remove',
            'shareId' => (string)$share->getFullId(),
            'type' => (int)$share->getShareType(),
            'node' => ['id' => $target['node']->getId(), 'path' => $target['path']],
            'recipient' => ['kind' => $target['recipient']->kind, 'id' => $target['recipient']->id],
            'before' => ShareAccess::snapshot($share),
        ]);
    }

    /**
     * Removes the share after checking everything again, when the call gives back the `plan_state` of the share as it
     * is now. A refusal of the core becomes a translated message; the log
     * keeps the exception class only, never its message, which can name paths.
     *
     * @param Folder $userFolder the user's folder
     * @param string $uid authenticated user
     * @param array{shareId?:string, path?:string, with?:string} $arguments validated arguments
     * @return array{removed:string, path:string, with:array{type:string, id:string, displayName:string}} the share removed
     * @throws ArgumentValidationException|ToolFailure as {@see self::plan()}, and when the core refuses to remove it; an
     *   argument error too without `plan_state`, once nothing else refuses
     * @throws PlanChanged when the share is not the one, or not in the state, the plan showed; nothing is removed
     */
    public function apply(Folder $userFolder, string $uid, array $arguments): array {
        $target = $this->prepare($userFolder, $uid, $arguments);
        PlanState::require($arguments);
        if (!PlanState::matches($this->stateOf($target, $uid), $arguments)) {
            throw new PlanChanged($this->planOf($target, $uid, true));
        }
        try {
            $this->shareManager->deleteShare($target['share']);
        } catch (\Exception $e) {
            $this->logger->warning('MCP unshare refused by Nextcloud', ['app' => 'mcp', 'exception_class' => $e::class]);
            throw new ToolFailure(FilesMessages::unshareRefused());
        }
        return [
            'removed' => (string)$target['share']->getFullId(),
            'path' => $target['path'],
            'with' => $target['recipient']->toArray(),
        ];
    }

    /**
     * The share to remove and what the plan shows about it, or the refusal.
     *
     * @param Folder $userFolder the user's folder
     * @param string $uid authenticated user
     * @param array<string, mixed> $arguments validated arguments
     * @return array{share:IShare, node:Node, path:string, folder:bool, recipient:ShareRecipient}
     * @throws ArgumentValidationException|ToolFailure for any refusal
     */
    private function prepare(Folder $userFolder, string $uid, array $arguments): array {
        $id = $arguments['shareId'] ?? null;
        $path = $arguments['path'] ?? null;
        $with = $arguments['with'] ?? null;
        self::assertOneWayToName($id, $path, $with);
        if ($id !== null) {
            [$share, $node] = $this->byId($userFolder, $uid, (string)$id);
            $shown = (string)($userFolder->getRelativePath($node->getPath()) ?? '/');
        } else {
            [$share, $node] = $this->byRecipient($userFolder, $uid, (string)$path, (string)$with);
            $shown = PathGuard::normalize((string)$path);
        }
        return ['share' => $share, 'node' => $node, 'path' => $shown, 'folder' => NodeAccess::isFolder($node), 'recipient' => $this->recipients->ofShare($share)];
    }

    /**
     * The share a `shareId` names, when it is one the user may remove.
     *
     * @param Folder $userFolder the user's folder
     * @param string $uid authenticated user
     * @param string $id full share id, as files_list_shares returns it
     * @return array{IShare, Node} the share and its node as the user sees it
     * @throws ToolFailure not found, for anything that is not the user's own or whose node is hidden; the Talk message only
     *   for the user's own attachment of a visible node of the user
     */
    private function byId(Folder $userFolder, string $uid, string $id): array {
        try {
            $share = $this->shareManager->getShareById($id);
        } catch (\Exception) {
            throw new ToolFailure(CommonMessages::notFound());
        }
        if ($share->getSharedBy() !== $uid || !$this->ownsTheNode($share, $uid)) {
            throw new ToolFailure(CommonMessages::notFound());
        }
        // Visibility comes before anything that would say more than "not found": a hidden node does not exist (invariant 1).
        $node = $this->access->nodeOf($userFolder, $share) ?? throw new ToolFailure(CommonMessages::notFound());
        if ($share->getShareType() === IShare::TYPE_ROOM) {
            throw new ToolFailure(FilesMessages::shareTypeUnsupported());
        }
        if (!$this->access->isRemovable($share, $uid)) {
            throw new ToolFailure(CommonMessages::notFound());
        }
        $this->access->assertGranted($uid, $share->getShareType());
        return [$share, $node];
    }

    /**
     * The share of one recipient on one own node.
     *
     * @param Folder $userFolder the user's folder
     * @param string $uid authenticated user
     * @param string $path user-relative path of the node
     * @param string $with `user:<uid>`, `group:<gid>` or `link`
     * @return array{IShare, Node} the share and its node
     * @throws ArgumentValidationException for an unknown recipient
     * @throws ToolFailure the grant of that type is missing, the node is not the user's own, or no share matches
     */
    private function byRecipient(Folder $userFolder, string $uid, string $path, string $with): array {
        $recipient = $this->recipients->resolve($with, 'with');
        $this->access->assertGranted($uid, $recipient->shareType());
        $node = $this->access->ownNode($userFolder, $uid, $path);
        $share = $this->access->findExisting($uid, $node, $recipient);
        if ($share === null || !$this->access->isRemovable($share, $uid)) {
            throw new ToolFailure(CommonMessages::notFound());
        }
        return [$share, $node];
    }

    /**
     * Invariant 5, literally: the node the share is on belongs to the user.
     *
     * @param IShare $share share created by the user
     * @param string $uid authenticated user
     * @return bool true when the node exists and its owner is the user
     */
    private function ownsTheNode(IShare $share, string $uid): bool {
        try {
            return $share->getNode()->getOwner()?->getUID() === $uid;
        } catch (NotFoundException) {
            return false;
        }
    }

    /**
     * @param mixed $id the shareId argument
     * @param mixed $path the path argument
     * @param mixed $with the with argument
     * @throws ArgumentValidationException unless the call has an id alone, or a path together with a recipient
     */
    private static function assertOneWayToName(mixed $id, mixed $path, mixed $with): void {
        $field = match (true) {
            $id !== null && ($path !== null || $with !== null) => 'shareId',
            $id !== null => null,
            $path !== null && $with !== null => null,
            $path !== null => 'with',
            $with !== null => 'path',
            default => 'shareId',
        };
        if ($field !== null) {
            throw new ArgumentValidationException('Invalid argument: ' . $field, $field,
                Translator::t('give either shareId, or path together with with'));
        }
    }
}
