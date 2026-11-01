<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files\Handler;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCA\Mcp\Tools\Files\CopyMoveContext;
use OCA\Mcp\Tools\Files\MoveConflict;
use OCA\Mcp\Tools\Files\FolderExposureGate;
use OCA\Mcp\Tools\Files\FilesMessages;

/** Owns files_move planning and confirmation, preserving native authorization and receipts. */
final class MoveHandler {
	/** @param CopyMoveContext $context Shared native checks and final destination policy. */
	public function __construct(private CopyMoveContext $context) {
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
	public function handle(Folder $root, string $userId, string $from, string $to, ?string $etag, bool $confirmedShared): array {
		$check = $this->context->reorganization->inspect($root, $from, $to, $etag);
		$this->context->policy()->assertProjected($check->source, $check->to);
		FolderExposureGate::assertMove($root, $check->source, $check->to, $this->context->visibilityGuard);
		$this->context->reorganization->assertUnlocked($check->source, $userId);
		if (($payload = $this->context->confirmBoth($check->source, $from, $check->destination, $check->to, $userId, $confirmedShared)) !== null) {
			return $payload;
		}
		// Everything is read before the move: afterwards the node answers from its new path, and a receipt
		// read too late is a claim about nothing.
		$receipt = $root->getRelativePath($check->source->getPath()) ?? '';
		$idBefore = (int)$check->source->getId();
		$versionsBefore = $this->context->versions($check->source, $userId);
		$sharesBefore = $this->context->report->shares($check->source, $userId);
		$moved = $this->context->locked(fn (): Node => $this->context->policy()->write($check->source, $check->to, fn (): Node => $check->source->move($this->context->absolute($root, $check->to))), $check->source, $userId);
		$this->context->visibilityGuard?->assertVisible($moved);
		$idAfter = (int)$moved->getId();
		return [
			'from' => $receipt,
			'to' => $check->to,
			'idBefore' => $idBefore,
			'idAfter' => $idAfter,
			'idPreserved' => $idAfter === $idBefore,
			'versionsBefore' => $versionsBefore,
			'versionsAfter' => $this->context->versions($moved, $userId),
			'sharesBefore' => $sharesBefore,
			'sharesAfter' => $this->context->report->shares($moved, $userId),
			'access' => $this->context->access->describe($moved, $userId),
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
		$check = $this->context->reorganization->inspect($root, $from, $to, $etag);
		$this->context->policy()->assertProjected($check->source, $check->to);
		FolderExposureGate::assertMove($root, $check->source, $check->to, $this->context->visibilityGuard);
		return [
			'action' => 'files_move',
			'from' => $root->getRelativePath($check->source->getPath()) ?? '',
			'to' => $check->to,
			'idBefore' => (int)$check->source->getId(),
			'isDir' => $check->source instanceof Folder,
			'versions' => $this->context->versions($check->source, $userId),
			'access' => $this->context->access->describe($check->source, $userId),
			'shared' => $this->context->sharedOf($userId, $check->source, $check->destination),
			'recoverable' => true,
			'message' => FilesMessages::planMove(),
		] + ($this->context->locks?->planNotice($check->source, $userId) ?? []);
	}
}
