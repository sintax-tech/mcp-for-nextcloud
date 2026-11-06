<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Files\Deletion;

use OCA\Mcp\Platform\Backups\BackupLocation;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Files\Deletion\Folder\DeleteFolderBoundary;
use OCA\Mcp\Modules\Files\Deletion\Folder\DeleteFolderMessages;
use OCP\Files\Node;
use OCP\Files\Folder;
use OCP\Share\IManager;

/** Reads the conservative deletion scope afresh at each boundary, before exposing a resource or calling delete. */
class DeleteEligibility {
	/**
	 * @param FilesAccessGuard $visibility Effective Files scope, Guests and exclusion policy.
	 * @param BackupLocation $backups Fresh reservation history including NFC identity.
	 * @param DeleteBackendGate $backend Native trash eligibility checks.
	 * @param IManager $shares Official share metadata, never raw share tables.
	 * @param DeleteFolderBoundary|null $folders Complete bounded folder admission; absent collaborators refuse folders.
	 * @param \OCP\Files\IRootFolder|null $roots Public canonical owner lookup for received shares.
	 */
	public function __construct(
		private FilesAccessGuard $visibility,
		private BackupLocation $backups,
		private DeleteBackendGate $backend,
		private IManager $shares,
		private ?DeleteFolderBoundary $folders = null,
		private ?\OCP\Files\IRootFolder $roots = null,
	) {
	}

	/**
	 * Resolves a visible source and refuses unsupported or unconfirmed admission before any effect.
	 *
	 * @param Folder $root Actor's personal Files root.
	 * @param string $uid Authenticated actor.
	 * @param string $path Actor-relative source.
	 * @param string|null $etag Optional ETag from the plan; otherwise the current prepare ETag is used later.
	 * @param int|null $id Optional source identity from the plan.
	 * @return Node Eligible visible file or folder; the legacy method name remains for existing callers.
	 * @throws ToolFailure For a refused gate, native permission, scope or identity.
	 */
	public function file(Folder $root, string $uid, string $path, ?string $etag = null, ?int $id = null): Node {
		$this->visibility->clearCache();
		$this->visibility->assertScopeReadable();
		$path = PathGuard::normalize($path);
		$file = NodeAccess::get($root, $path, $this->visibility);
		if ($file instanceof Folder) {
			($this->folders ?? throw new ToolFailure(DeleteFolderMessages::refused()))->assertSource($root, $uid, $file);
		} else {
			NodeAccess::requireFile($file);
		}
		$this->admit($root, $uid, $file, $path, $etag, $id);
		return $file;
	}

	/**
	 * Applies common admission to one already resolved node; folder callers reuse it for every descendant.
	 * @param Folder $root Actor's root.
	 * @param string $uid Authenticated actor.
	 * @param Node $file Current candidate.
	 * @param string $path Actor-relative path.
	 * @param string|null $etag Optional expected ETag.
	 * @param int|null $id Optional expected identity.
	 * @return void
	 */
	private function admit(Folder $root, string $uid, Node $file, string $path, ?string $etag = null, ?int $id = null): void {
		$this->admission()->source($root, $uid, $file, $path, $etag, $id);
	}

	/**
	 * Returns a complete observation for folders, without treating partial admission as permission.
	 * @param Folder $root Actor's personal root.
	 * @param string $uid Authenticated actor.
	 * @param Node $node Admitted source.
	 * @return array<string,mixed>|null Folder observation, or null for an individual file.
	 */
	public function tree(Folder $root, string $uid, Node $node): ?array {
		if (!$node instanceof Folder) {
			return null;
		}
		return (new \OCA\Mcp\Modules\Files\Deletion\Folder\DeleteFolderAdmission($this->visibility, $this->admission()))->inspect(
			$this->folders ?? throw new ToolFailure(DeleteFolderMessages::refused()), $root, $uid, $node);
	}

	/**
	 * Revalidates folder identity and the entire observed tree immediately before the native call.
	 * @param Folder $root Actor's root.
	 * @param string $uid Authenticated actor.
	 * @param string $path Source path.
	 * @param int $id Expected identity.
	 * @param string $etag Expected ETag.
	 * @param array<string,mixed> $before Complete initial observation.
	 * @return void
	 */
	public function verifyTree(Folder $root, string $uid, string $path, int $id, string $etag, array $before): void {
		$current = $this->file($root, $uid, $path, $etag, $id);
		if (!$current instanceof Folder || $this->tree($root, $uid, $current) !== $before) {
			throw new ToolFailure(\OCA\Mcp\Platform\FileAccess\CommonMessages::conflict());
		}
	}

	/**
	 * Reports shared impact from a complete folder observation or fresh source admission.
	 *
	 * @param Folder $root Actor root.
	 * @param string $uid Actor.
	 * @param Node $node Admitted node.
	 * @param array<string,mixed>|null $tree Complete folder observation when present.
	 * @return bool Current shared impact, never recipient identities.
	 */
	public function shared(Folder $root, string $uid, Node $node, ?array $tree = null): bool {
		if ($tree !== null) { return ($tree['shared'] ?? false) === true; }
		return $this->admission()->source($root, $uid, $node, (string)$root->getRelativePath($node->getPath()));
	}

	/** @return DeleteNodeAdmission A fresh collaborator; no authorization is cached across admission frontiers. */
	private function admission(): DeleteNodeAdmission {
		return new DeleteNodeAdmission($this->visibility, $this->backups, $this->backend, $this->shares, $this->roots);
	}

	/** @return void Invalidate observations after the native attempt, including partial failure. */
	public function invalidate(): void {
		$this->visibility->clearCache();
	}
}
