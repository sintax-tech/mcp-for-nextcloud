<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files\Handler;

use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\Common\PathGuard;
use OCA\Mcp\Tools\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCA\Mcp\Tools\Files\CopyMoveContext;
use OCA\Mcp\Tools\Files\FileTypePreflight;
use OCA\Mcp\Tools\Files\ListedTreeCopy;
use OCA\Mcp\Tools\Files\FolderExposureGate;
use OCA\Mcp\Tools\Files\FilesMessages;

/** Owns files_copy planning and confirmation, preserving native authorization and receipts. */
final class CopyHandler {
	/** @param CopyMoveContext $context Shared native checks and final destination policy. */
	public function __construct(private CopyMoveContext $context) {
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
	public function handle(Folder $root, string $userId, string $from, string $to, ?string $etag, bool $confirmedShared): array {
		$source = $this->context->writable($root, $from);
		FolderExposureGate::assertExposure($source, $this->context->visibilityGuard);
		NodeAccess::checkEtag($source, $etag);
		$to = $this->context->notBackup(PathGuard::normalize($to, 'to'));
		$this->context->assertFree($root, $to);
		$destination = $this->context->destination($root, $to);
		$manifest = (new FileTypePreflight($this->context))->inspect($source, $to, $this->context->policy()->snapshot(), true);
		$measured = $manifest ?? $this->context->reorganization->measure($source);
		if (($payload = $this->context->confirmBoth($source, $from, $destination, $to, $userId, $confirmedShared)) !== null) {
			return $payload;
		}
		$receipt = $root->getRelativePath($source->getPath()) ?? '';
		// Reading a locked file is allowed, so the source is not checked; files_lock may still refuse a copy between
		// storages, and that refusal is explained.
		$result = (new ListedTreeCopy($this->context))->execute($root, $userId, $source, $to, $manifest);
		$copy = $result['node'];

		$out = [
			'from' => $receipt,
			'to' => $to,
			'idBefore' => (int)$source->getId(),
			'idAfter' => $copy === null ? null : (int)$copy->getId(),
			'nodes' => $measured['nodes'],
			'bytes' => $measured['bytes'],
			'access' => $copy === null ? null : $this->context->access->describe($copy, $userId),
		];
		// D10/B4 adds a partial receipt only for a folder copied from the validated list.
		if ($result['listed']) {
			$out += [
				'partial' => $result['partial'],
				'created' => $result['created'],
				'conflicts' => $result['conflicts'],
				'not_copied' => $result['not_copied'],
			];
		}
		return $out;
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
		$source = $this->context->writable($root, $from);
		FolderExposureGate::assertExposure($source, $this->context->visibilityGuard);
		NodeAccess::checkEtag($source, $etag);
		$to = $this->context->notBackup(PathGuard::normalize($to, 'to'));
		$this->context->assertFree($root, $to);
		$destination = $this->context->destination($root, $to);
		$manifest = (new FileTypePreflight($this->context))->inspect($source, $to, $this->context->policy()->snapshot(), true);
		$measured = $manifest ?? $this->context->reorganization->measure($source);
		return [
			'action' => 'files_copy',
			'from' => $root->getRelativePath($source->getPath()) ?? '',
			'to' => $to,
			'idBefore' => (int)$source->getId(),
			'nodes' => $measured['nodes'],
			'bytes' => $measured['bytes'],
			'access' => $this->context->access->describe($source, $userId),
			'shared' => $this->context->sharedOf($userId, $source, $destination),
			'recoverable' => true,
			'message' => FilesMessages::planCopy(),
		];
	}
}
