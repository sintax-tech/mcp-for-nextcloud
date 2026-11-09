<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Files\Deletion;

use OCA\Mcp\Platform\FileAccess\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCA\Mcp\Platform\Logging\ExceptionDetails;
use OCA\Mcp\Modules\Files\Deletion\DeleteEligibility;
use OCA\Mcp\Modules\Files\Deletion\DeleteMessages;
use OCA\Mcp\Modules\Files\Deletion\DeleteOutcome;
use OCA\Mcp\Modules\Files\Deletion\TrashReceiptMessages;
use OCA\Mcp\Modules\Files\Integration\TrashReceiptVerifier;
use OCP\Files\Folder;
use OCA\Mcp\Modules\Files\Deletion\Sharing\DeleteSharedConfirmation;

/** Sole opt-in file deletion sink; policy admission belongs to ToolRegistry and resource admission is repeated here. */
final class DeleteFileHandler {
	/**
	 * @param DeleteEligibility $eligibility Fresh scope, identity, native ACL and backend admission.
	 * @param LockAwareWrite $locks Existing lock-awareness, with its documented native/token-lock race limits.
	 * @param \OCA\Mcp\Modules\Files\Integration\DeleteTrashVeto $veto Refuses observed native opt-outs.
	 * @param \Psr\Log\LoggerInterface $logger Logs only the failure class, without resource data.
	 * @param TrashReceiptVerifier|null $receipts Reads only the actor's own trash bin after a removal; absent means no trash receipt.
	 */
	public function __construct(private DeleteEligibility $eligibility, private LockAwareWrite $locks, private \Psr\Log\LoggerInterface $logger, private \OCA\Mcp\Modules\Files\Integration\DeleteTrashVeto $veto, private ?TrashReceiptVerifier $receipts = null) {
	}

	/**
	 * Builds a read-only concrete plan after fresh admission.
	 *
	 * @param Folder $root Actor's own root.
	 * @param string $uid Authenticated actor.
	 * @param array<string, mixed> $arguments Validated tool arguments.
	 * @return array<string, mixed> Concrete plan with no persisted approval or backup.
	 */
	public function plan(Folder $root, string $uid, array $arguments): array {
		$file = $this->eligibility->file($root, $uid, $arguments['path'], $arguments['etag'] ?? null, $arguments['id'] ?? null);
		$tree = $this->eligibility->tree($root, $uid, $file);
		$shared = $this->eligibility->shared($root, $uid, $file, $tree);
		$this->locks->assertWritable($file, $uid);
		$destination = $this->receipts?->destination($file, $uid);
		return ['action' => 'files_delete', 'path' => $root->getRelativePath($file->getPath()),
			'id' => (int)$file->getId(), 'etag' => (string)$file->getEtag(), 'size' => $file->getSize(),
			'shared' => $shared, 'requiresSharedConfirmation' => $shared, 'recoveryGuaranteed' => false, 'backupCreated' => false,
			'message' => DeleteMessages::risk() . ' ' . DeleteMessages::supported() . ($shared ? ' ' . DeleteSharedConfirmation::message() : '') . TrashReceiptMessages::planNote($destination)]
			+ ($destination === null ? [] : ['trashDestination' => TrashReceiptMessages::PLAN[$destination]]) + ($tree === null ? [] : ['nodeType' => 'folder', 'tree' => array_diff_key($tree, ['fingerprint' => true])]);
	}

	/**
	 * Attempts native deletion once and measures the admitted source afterwards.
	 *
	 * @param Folder $root Actor's own root.
	 * @param string $uid Authenticated actor.
	 * @param array<string, mixed> $arguments Validated, explicitly confirmed arguments from WriteGate.
	 * @return array<string, mixed> Measured MCP receipt, including error/unknown outcomes.
	 */
	public function handle(Folder $root, string $uid, array $arguments): array {
		$path = PathGuard::normalize($arguments['path']);
		$file = $this->eligibility->file($root, $uid, $path, $arguments['etag'] ?? null, $arguments['id'] ?? null);
		$id = (int)$file->getId();
		$etag = (string)$file->getEtag();
		$tree = $this->eligibility->tree($root, $uid, $file);
		$confirmedShared = ($arguments['confirm_shared'] ?? false) === true;
		$request = DeleteSharedConfirmation::request($this->eligibility->shared($root, $uid, $file, $tree), $confirmedShared);
		if ($request !== null) { return $request; }
		$this->locks->assertWritable($file, $uid);
		// A second resolution before the sink must still describe the exact file prepared above.
		$file = $this->eligibility->file($root, $uid, $path, $etag, $id);
		// Ownership is read before the attempt; a received share item is never looked up in the owner's trash bin.
		$destination = $this->receipts?->destination($file, $uid) ?? TrashReceiptVerifier::UNKNOWN;
		$trash = $this->receipts === null ? null
			: fn (string $default): array => $this->receipts->receipt($destination, $uid, $id, $file instanceof Folder, $default);
		$failed = false;
		$attempted = false;
		try {
			$this->veto->run($file, function () use ($file, $root, $uid, $path, $id, $etag, $tree, $confirmedShared, &$attempted): void {
				$this->locks->run(function () use ($file, $root, $uid, $path, $id, $etag, $tree, $confirmedShared, &$attempted): void {
					if ($file instanceof Folder) {
						$this->eligibility->verifyTree($root, $uid, $path, $id, $etag, $tree ?? []);
					}
					if (!$file instanceof Folder) {
						$file = $this->eligibility->file($root, $uid, $path, $etag, $id);
						DeleteSharedConfirmation::assertConfirmed($this->eligibility->shared($root, $uid, $file), $confirmedShared);
					}
					$attempted = true;
					$file->delete();
				}, $file, $uid);
			});
		} catch (\Throwable $error) {
			if (!$attempted) { throw $error; }
			try {
				$this->logger->warning('MCP native file deletion did not complete normally: {exception_class} (code {exception_code})', ['app' => 'mcp', 'exception_class' => $error::class, 'exception_code' => ExceptionDetails::code($error)]);
			} catch (\Throwable) {
				// Logging failure cannot suppress observation of an operation that may already have changed the source.
			}
			$failed = true;
		} finally {
			try {
				$this->eligibility->invalidate();
			} catch (\Throwable $error) {
				// Post-attempt invalidation failure must not erase observation or grant another native attempt.
				$failed = true;
				try {
					$this->logger->warning('MCP file deletion cache invalidation failed: {exception_class} (code {exception_code})', ['app' => 'mcp', 'exception_class' => $error::class, 'exception_code' => ExceptionDetails::code($error)]);
				} catch (\Throwable) {
					// Observation still runs when diagnostics are also unavailable.
				}
			}
		}
		if ($file instanceof Folder) {
			return \OCA\Mcp\Modules\Files\Deletion\Folder\DeleteFolderOutcome::observe($root, $path, $id, $etag, $failed, $tree ?? [],
				fn (Folder $current): array => $this->eligibility->tree($root, $uid, $current) ?? [], $trash);
		}
		return DeleteOutcome::observe($root, $path, $id, $etag, $failed, $trash);
	}
}
