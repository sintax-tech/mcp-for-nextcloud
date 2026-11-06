<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Coordinate the existing single-copy and single-move handlers and previews. */
namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;

/** Preserves the existing CopyMove operations and refusal order. */
final class CopyMove {
	/**
	 * Bind the existing collaborators without storing operation state.
	 * @param CopyMoveContext $context context collaborator.
	 * @return void
	 */
	public function __construct(
		private readonly CopyMoveContext $context,
	) {
	}

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
	 * @return array{from:string, to:string, idBefore:int, idAfter:int|null, nodes:int, bytes:int, access:array<string, mixed>|null, partial?:bool, created?:list<string>, conflicts?:list<array{path:string,reason:string}>, not_copied?:list<string>}|array<string, mixed>
	 *   the receipt, or the shared-write confirmation instead of a receipt when one is still missing
	 * @throws \InvalidArgumentException for a path with traversal or control characters
	 * @throws ToolFailure for an occupied destination, a stale ETag, a ceiling or a denied write
	 */
	public function copy(Folder $root, string $userId, string $from, string $to, ?string $etag, bool $confirmedShared): array {
		return (new \OCA\Mcp\Modules\Files\Reorganization\CopyHandler($this->context))->handle($root, $userId, $from, $to, $etag, $confirmedShared);
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
		return (new \OCA\Mcp\Modules\Files\Reorganization\MoveHandler($this->context))->handle($root, $userId, $from, $to, $etag, $confirmedShared);
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
		return (new \OCA\Mcp\Modules\Files\Reorganization\CopyHandler($this->context))->planCopy($root, $userId, $from, $to, $etag);
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
		return (new \OCA\Mcp\Modules\Files\Reorganization\MoveHandler($this->context))->planMove($root, $userId, $from, $to, $etag);
	}
}
