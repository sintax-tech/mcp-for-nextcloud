<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Modules\Files\Access\FolderExposureGate;
use OCA\Mcp\Platform\FileAccess\Locks\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;

final class CopyMove {
    public function __construct(
        private NodeAccessInfo $access,
        private MoveReport $report,
        private \OCP\IUserManager $userManager,
        private MoveInspection $moveInspection,
        private TreeMeasure $treeMeasure,
        private MoveExecution $moveExecution,
        private FilesAccessGuard $visibilityGuard,
        private ?LockAwareWrite $locks = null,
    ) {}

    /**
     * Copies a file or a folder to a free path, refusing before anything is copied when the source is over
     * the ceilings.
     *
     * A copy is a new node: it gets a new id and the description promises nothing about the shares or the
     * versions of the original, which the Nextcloud copy flow does not carry over.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param string $from source path, user-relative
     * @param string $to destination path, user-relative
     * @param string|null $etag ETag the caller read before, null to skip the check
     * @param bool $confirmedShared whether the caller passed confirm_shared
     * @return array{from:string, to:string, idBefore:int, idAfter:int, nodes:int, bytes:int, access:array<string, mixed>}|array<string, mixed>
     *   the receipt, or the shared-write confirmation instead of a receipt when one is still missing
     * @throws \InvalidArgumentException for a path with traversal or control characters
     * @throws ToolFailure for an occupied destination, a stale ETag, a ceiling or a denied write
     */
    public function copy(Folder $root, string $userId, string $from, string $to, ?string $etag, bool $confirmedShared): array {
        $source = $this->moveInspection->writable($root, $from);
        FolderExposureGate::assertExposure($source, $this->visibilityGuard);
        NodeAccess::checkEtag($source, $etag);
        $to = MovePaths::notBackup(PathGuard::normalize($to, 'to'));
        $this->moveInspection->assertFree($root, $to);
        $measured = $this->treeMeasure->measure($source);
        if (($payload = $this->moveInspection->confirmBoth($source, $from, $this->moveInspection->destination($root, $to), $to, $userId, $confirmedShared)) !== null) {
            return $payload;
        }
        $receipt = $root->getRelativePath($source->getPath()) ?? '';
        // Reading a locked file is allowed, so the source is not checked; files_lock may still refuse a copy between
        // storages, and that refusal is explained.
        $copy = NodeAccess::mutate(fn () => $this->locks === null ? $source->copy(MovePaths::absolute($root, $to)) : $this->locks->capture(fn (): Node => $source->copy(MovePaths::absolute($root, $to)), $source, $userId), $this->visibilityGuard);
        $this->visibilityGuard->assertVisible($copy);
        return [
            'from' => $receipt,
            'to' => $to,
            'idBefore' => (int)$source->getId(),
            'idAfter' => (int)$copy->getId(),
            'nodes' => $measured['nodes'],
            'bytes' => $measured['bytes'],
            'access' => $this->access->describe($copy, $userId),
        ];
    }

    /**
     * Moves or renames a node inside the same storage, refusing before anything changes when the guards
     * say no, and reporting afterwards what could be measured.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param string $from source path, user-relative
     * @param string $to destination path, user-relative
     * @param string|null $etag ETag the caller read before, null to skip the check
     * @param bool $confirmedShared whether the caller passed confirm_shared
     * @return array{from:string, to:string, idBefore:int, idAfter:int, idPreserved:bool, versionsBefore:int|null, versionsAfter:int|null, sharesBefore:int|null, sharesAfter:int|null, access:array<string, mixed>}|array<string, mixed>
     *   the report, or the shared-write confirmation instead of a report when one is still missing
     * @throws \InvalidArgumentException for a path with traversal or control characters
     * @throws ToolFailure for an occupied destination, a stale ETag, another storage, a folder into itself or a denied write
     */
    public function move(Folder $root, string $userId, string $from, string $to, ?string $etag, bool $confirmedShared): array {
        $check = $this->moveInspection->inspect($root, $from, $to, $etag);
        FolderExposureGate::assertMove($root, $check->source, $check->to, $this->visibilityGuard);
        $this->moveInspection->assertUnlocked($check->source, $userId);
        if (($payload = $this->moveInspection->confirmBoth($check->source, $from, $check->destination, $check->to, $userId, $confirmedShared)) !== null) {
            return $payload;
        }
        // Everything is read before the move: afterwards the node answers from its new path, and a receipt
        // read too late is a claim about nothing.
        $receipt = $root->getRelativePath($check->source->getPath()) ?? '';
        $idBefore = (int)$check->source->getId();
        $versionsBefore = $this->versions($check->source, $userId);
        $sharesBefore = $this->report->shares($check->source, $userId);
        $moved = $this->moveExecution->locked(fn (): Node => $check->source->move(MovePaths::absolute($root, $check->to)), $check->source, $userId);
        $this->visibilityGuard->assertVisible($moved);
        $idAfter = (int)$moved->getId();
        return [
            'from' => $receipt,
            'to' => $check->to,
            'idBefore' => $idBefore,
            'idAfter' => $idAfter,
            'idPreserved' => $idAfter === $idBefore,
            'versionsBefore' => $versionsBefore,
            'versionsAfter' => $this->versions($moved, $userId),
            'sharesBefore' => $sharesBefore,
            'sharesAfter' => $this->report->shares($moved, $userId),
            'access' => $this->access->describe($moved, $userId),
        ];
    }

    /**
     * The plan of `files_copy`: both ends, what the copy weighs and the id the original keeps.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param string $from source path, user-relative
     * @param string $to destination path, user-relative
     * @param string|null $etag ETag the caller read before, null to skip the check
     * @return array<string, mixed>
     * @throws \InvalidArgumentException for a path with traversal or control characters
     * @throws ToolFailure for an occupied destination, a stale ETag, a ceiling or a denied write
     */
    public function planCopy(Folder $root, string $userId, string $from, string $to, ?string $etag): array {
        $source = $this->moveInspection->writable($root, $from);
        FolderExposureGate::assertExposure($source, $this->visibilityGuard);
        NodeAccess::checkEtag($source, $etag);
        $to = MovePaths::notBackup(PathGuard::normalize($to, 'to'));
        $this->moveInspection->assertFree($root, $to);
        $measured = $this->treeMeasure->measure($source);
        $destination = $this->moveInspection->destination($root, $to);
        return [
            'action' => 'files_copy',
            'from' => $root->getRelativePath($source->getPath()) ?? '',
            'to' => $to,
            'idBefore' => (int)$source->getId(),
            'nodes' => $measured['nodes'],
            'bytes' => $measured['bytes'],
            'access' => $this->access->describe($source, $userId),
            'shared' => $this->moveInspection->sharedOf($userId, $source, $destination),
            'recoverable' => true,
            'message' => ReorganizationMessages::planCopy(),
        ];
    }

    /**
     * The plan of `files_move`: where the node goes, what it carries and what still shares it.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param string $from source path, user-relative
     * @param string $to destination path, user-relative
     * @param string|null $etag ETag the caller read before, null to skip the check
     * @return array<string, mixed>
     * @throws \InvalidArgumentException for a path with traversal or control characters
     * @throws MoveConflict when the destination is taken, the folder would land inside itself, or the two ends are on different storages
     * @throws ToolFailure when the source is missing, unreadable, not changeable, or the destination cannot receive
     */
    public function planMove(Folder $root, string $userId, string $from, string $to, ?string $etag): array {
        $check = $this->moveInspection->inspect($root, $from, $to, $etag);
        FolderExposureGate::assertMove($root, $check->source, $check->to, $this->visibilityGuard);
        return [
            'action' => 'files_move',
            'from' => $root->getRelativePath($check->source->getPath()) ?? '',
            'to' => $check->to,
            'idBefore' => (int)$check->source->getId(),
            'isDir' => $check->source instanceof Folder,
            'versions' => $this->versions($check->source, $userId),
            'access' => $this->access->describe($check->source, $userId),
            'shared' => $this->moveInspection->sharedOf($userId, $check->source, $check->destination),
            'recoverable' => true,
            'message' => ReorganizationMessages::planMove(),
        ] + ($this->locks?->planNotice($check->source, $userId) ?? []);
    }

    /**
     * Versions belong to files: a folder has none of its own, and asking anyway would report a count nobody
     * could check.
     *
     * @param Node $node the node to count versions of
     * @param string $userId authenticated user
     * @return int|null the version count, or null for a folder or when versioning is off
     */
    private function versions(Node $node, string $userId): ?int {
        return $node instanceof File ? $this->report->versions($node, $this->user($userId)) : null;
    }

    /**
     * @param string $userId authenticated user
     * @return \OCP\IUser|null the user, when the manager knows them
     */
    private function user(string $userId): ?\OCP\IUser {
        return $this->userManager->get($userId);
    }
}
