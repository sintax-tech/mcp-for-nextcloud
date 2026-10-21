<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Checkout;

use OCA\Mcp\Mcp\Confirmation\ConfirmationMessages;
use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use OCA\Mcp\Modules\Files\Creation\CreationMessages;
use OCA\Mcp\Modules\Files\Creation\FileCreation;
use OCA\Mcp\Modules\Files\Creation\FileExists;
use OCA\Mcp\Modules\Files\Editing\EditingMessages;
use OCA\Mcp\Modules\Files\Editing\FileBackup;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Platform\FileAccess\Locks\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\Locks\LockMessages;
use OCA\Mcp\Platform\FileAccess\Locks\LockWriteFailure;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

final class CheckoutWriter {
    public function __construct(
        private IRootFolder $rootFolder,
        private CheckoutPolicy $checkoutPolicy,
        private FileBackup $backup,
        private SharedWriteGuard $guard,
        private NodeAccessInfo $accessInfo,
        private LoggerInterface $logger,
        private FilesAccessGuard $visibilityGuard,
        private ?FileCreation $creation = null,
        private ?LockAwareWrite $locks = null,
    ) {}

    /**
     * The write itself, after the token and the policy were already accepted.
     *
     * @param CheckoutToken $row the spent token, carrying the user, path and ETag to enforce
     * @param File $file the node the token points at
     * @param string $body temporary file holding the uploaded bytes
     * @return Response the JSON receipt, the confirmation payload or a refusal
     */
    public function replace(CheckoutToken $row, File $file, string $body): array {
        try {
            $this->locks?->assertWritable($file, $row->userId, $row->path, $this->actsAsUser($row->userId));
        } catch (LockWriteFailure $e) {
            throw new CheckoutFailure(Http::STATUS_LOCKED, $e->getMessage());
        }
        $this->checkoutPolicy->spend($row);
        $root = $this->rootFolder->getUserFolder($row->userId);
        $access = $this->accessInfo->describe($file, $row->userId);
        // The scope recorded at checkout can be stale: a file may have moved into a share in between.
        if ($access['scope'] !== NodeAccessInfo::PERSONAL && !$row->sharedConfirmed) {
            throw new CheckoutFailure(Http::STATUS_CONFLICT, '', $this->guard->request($access, $row->path, ConfirmationMessages::confirmAdviceCheckout()));
        }
        if (!$file->isUpdateable()) {
            throw new CheckoutFailure(Http::STATUS_FORBIDDEN, FileAccessMessages::forbidden());
        }
        $copy = '';
        try {
            NodeAccess::checkEtag($file, $row->etag);
            $asUser = $this->actsAsUser($row->userId);
            $copy = $this->backup->prepare($root, $file, $row->path, $row->userId, $row->etag, $asUser);
            $this->visibilityGuard->assertVisible($file);
            $write = static fn () => $file->putContent((string)file_get_contents($body));
            // Checked again right before the write: the backup took time, and files_lock does not refuse every lock.
            NodeAccess::mutate(fn () => $this->locks === null ? $write() : $this->locks->run($write, $file, $row->userId, $row->path, $asUser), $this->visibilityGuard);
            $node = NodeAccess::requireFile(NodeAccess::get($root, $row->path, $this->visibilityGuard));
        } catch (LockWriteFailure $e) {
            // The link is spent all the same; a backup taken before the refusal holds the untouched original.
            return $this->lockRefusal($e, $copy);
        } catch (LockedException $e) {
            $failure = NodeAccess::lockFailure($e);
            return $failure instanceof LockWriteFailure
                ? $this->lockRefusal($failure, $copy)
                : throw new CheckoutFailure(Http::STATUS_LOCKED, $failure->getMessage() . ' ' . LockMessages::newCheckoutNeeded());
        } catch (ToolFailure $e) {
            $conflict = $e->getMessage() === FileAccessMessages::conflict();
            $message = $conflict ? CheckoutMessages::uploadConflict() : $e->getMessage();
            throw new CheckoutFailure($conflict ? Http::STATUS_CONFLICT : Http::STATUS_BAD_REQUEST, $message);
        } catch (\Throwable) {
            $this->logger->error('MCP checkout upload failed', ['app' => 'mcp']);
            // The copy exists by now, so the message can point at the one thing that still holds the original.
            throw new CheckoutFailure(Http::STATUS_INTERNAL_SERVER_ERROR, CheckoutMessages::uploadFailed($copy));
        }
        return [
            'path' => $row->path,
            'size' => (int)filesize($body),
            'etag' => (string)$node->getEtag(),
            'access' => $this->accessInfo->describe($node, $row->userId),
            'backup' => $copy,
        ];
    }

    /**
     * Creates the new file a files_upload link names, after the body was kept.
     *
     * The same order as an upload: the files.create grant, then where the file goes — the name is still one Nextcloud
     * accepts, the folder still exists, is visible, still lets the user add a file and the name is still free — then
     * the shared scope and the size the plan declared. None of these writes anything, so none of them costs the link.
     * Only then is the link spent and the file written, never over a file with content that appeared in the meantime.
     *
     * @param CheckoutToken $row the create token, already found, unspent and of this session
     * @param string $body temporary file holding the uploaded bytes, possibly empty
     * @return Response 201 with the receipt, 409 for a taken name or a missing shared confirmation, or a refusal
     */
    public function create(CheckoutToken $row, string $body): array {
        $uid = $row->userId;
        $creation = $this->creation ?? throw new \LogicException('FileCreation is not wired');
        try {
            $target = NodeAccess::run(fn (): array => $creation->target($this->rootFolder->getUserFolder($uid), $row->path));
            $access = $this->accessInfo->describe($target['folder'], $uid);
            if ($access['permissions']['update'] === false) {
                throw new ToolFailure(FileAccessMessages::forbidden());
            }
            FileCreation::assertCreatable($target['folder']);
        } catch (\Throwable $e) {
            return $this->createRefusal($e);
        }
        // The scope recorded with the link can be stale: the folder may have been shared in between.
        if ($access['scope'] !== NodeAccessInfo::PERSONAL && !$row->sharedConfirmed) {
            throw new CheckoutFailure(Http::STATUS_CONFLICT, '', $this->guard->request($access, $row->path, ConfirmationMessages::confirmAdviceUpload()));
        }
        $size = (int)filesize($body);
        $declared = $row->declaredSize();
        if ($size === 0 && $declared !== null && $declared > 0) {
            throw new CheckoutFailure(Http::STATUS_BAD_REQUEST, CheckoutMessages::uploadEmptyDeclared($declared));
        }
        $this->checkoutPolicy->spend($row, CheckoutMessages::createTokenSpent());
        $handle = null;
        try {
            $content = $size === 0 ? '' : ($handle = fopen($body, 'rb'));
            if ($content === false) {
                throw new ToolFailure(CreationMessages::createFailed());
            }
            $file = NodeAccess::run(fn (): \OCP\Files\File => $creation->write($target['folder'], $target['name'], $content, $uid, $this->actsAsUser($uid)));
            return $creation->receipt($target, $file, $uid);
        } catch (\Throwable $e) {
            return $this->createRefusal($e);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * The status a refusal of a new file maps to, so the agent knows whether to rename, ask or give up.
     *
     * @param \Throwable $e what target() or write() raised
     * @return Response 409 for a taken name, 400 for a name or a path Nextcloud refuses, 403 for a denied folder,
     *         404 for a missing or hidden one, 423 for a lock, 507 for a full quota and 500 for a write that failed
     */
    private function createRefusal(\Throwable $e): never {
        if ($e instanceof FileExists) {
            throw new CheckoutFailure(Http::STATUS_CONFLICT, $e->getMessage());
        }
        if ($e instanceof ArgumentValidationException) {
            throw new CheckoutFailure(Http::STATUS_BAD_REQUEST, $e->clientMessage());
        }
        if ($e instanceof LockWriteFailure) {
            throw new CheckoutFailure(Http::STATUS_LOCKED, $e->getMessage());
        }
        if (!$e instanceof ToolFailure) {
            $this->logger->error('MCP create upload failed', ['app' => 'mcp', 'exception_class' => $e::class]);
            throw new CheckoutFailure(Http::STATUS_INTERNAL_SERVER_ERROR, CreationMessages::createFailed());
        }
        match ($e->getMessage()) {
            FileAccessMessages::forbidden() => throw new CheckoutFailure(Http::STATUS_FORBIDDEN, $e->getMessage()),
            EditingMessages::backupPath() => throw new CheckoutFailure(Http::STATUS_BAD_REQUEST, $e->getMessage()),
            FileAccessMessages::locked() => throw new CheckoutFailure(Http::STATUS_LOCKED, $e->getMessage()),
            FileAccessMessages::insufficientQuota() => throw new CheckoutFailure(Http::STATUS_INSUFFICIENT_STORAGE, $e->getMessage()),
            CreationMessages::createFailed() => throw new CheckoutFailure(Http::STATUS_INTERNAL_SERVER_ERROR, $e->getMessage()),
            default => throw new CheckoutFailure(Http::STATUS_NOT_FOUND, $e->getMessage()),
        };
    }

    /**
     * @param LockWriteFailure $e the lock that refused an upload whose link is already spent
     * @param string $copy user-relative path of the backup taken before, '' when there is none
     * @return Response 423 with the explanation, where the original is, and the advice to run a new checkout
     */
    private function lockRefusal(LockWriteFailure $e, string $copy): never {
        throw new CheckoutFailure(Http::STATUS_LOCKED, implode(' ', array_filter([
            $e->getMessage(),
            $copy === '' ? '' : EditingMessages::originalKept($copy),
            LockMessages::newCheckoutNeeded(),
        ])));
    }

    /**
     * Whether this request writes as the owner of the link. A capability request has no user session, and files_lock
     * then refuses even the owner's own manual lock, so the check has to refuse it too.
     *
     * @param string $uid owner of the link
     * @return bool true when the session user is that owner
     */
    private function actsAsUser(string $uid): bool {
        return $this->checkoutPolicy->actsAsUser($uid);
    }
}
