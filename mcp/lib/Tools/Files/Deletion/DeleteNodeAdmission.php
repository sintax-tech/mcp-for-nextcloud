<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tools\Files\Deletion;

use OCA\Mcp\Platform\Backups\BackupLocation;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Tools\Files\BackupWriteBoundary;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Share\IManager;

/** Native admission shared by a standalone source and a complete, call-local folder inspection. */
final class DeleteNodeAdmission {
	/** @var Sharing\DeleteShareAdmission Call-local origin and share metadata; never retained between frontiers. */
	private Sharing\DeleteShareAdmission $sharing;

	/**
	 * @param FilesAccessGuard $visibility Effective scope, Guests and exclusion gate.
	 * @param BackupLocation $backups Fresh reserved names.
	 * @param DeleteBackendGate $backend Native eligibility through the actor's node.
	 * @param IManager $shares Supported share metadata.
	 * @param \OCP\Files\IRootFolder|null $roots Canonical owner lookup for local received shares.
	 */
	public function __construct(private FilesAccessGuard $visibility, private BackupLocation $backups, private DeleteBackendGate $backend, IManager $shares, ?\OCP\Files\IRootFolder $roots = null) {
		$this->sharing = new Sharing\DeleteShareAdmission(new Sharing\DeleteLocalOrigin($backups, $roots), $shares);
	}

	/**
	 * Admits a standalone source and its external ancestor chain exactly once at this boundary.
	 * @param Folder $root Actor's personal root.
	 * @param string $uid Actor of the operation.
	 * @param Node $node Fresh source.
	 * @param string $path Actor-relative source path.
	 * @param string|null $etag Expected source ETag when provided.
	 * @param int|null $id Expected identity when provided.
	 * @return bool Shared impact of the admitted source.
	 */
	public function source(Folder $root, string $uid, Node $node, string $path, ?string $etag = null, ?int $id = null): bool {
		$this->metadata($node, $path, $etag, $id);
		try { $shared = $this->sharing->source($root, $uid, $node); }
		catch (\Throwable) { throw new ToolFailure(DeleteMessages::supported()); }
		$this->backend->check($node, $uid);
		return $shared;
	}

	/**
	 * Admits one descendant whose parents are separately checked in the same complete inspection.
	 * @param string $uid Actor of the operation.
	 * @param Node $node Fresh descendant.
	 * @param string $path Actor-relative path.
	 * @return bool Shared impact of the admitted descendant.
	 */
	public function descendant(string $uid, Node $node, string $path): bool {
		$this->metadata($node, $path);
		try { $shared = $this->sharing->descendant($uid, $node); }
		catch (\Throwable) { throw new ToolFailure(DeleteMessages::supported()); }
		$this->backend->check($node, $uid);
		return $shared;
	}

	/**
	 * Applies the common resource and identity checks without share-tree traversal.
	 * @param Node $node Visible candidate.
	 * @param string $path Actor-relative path.
	 * @param string|null $etag Expected source ETag.
	 * @param int|null $id Expected source identity.
	 * @return void
	 */
	private function metadata(Node $node, string $path, ?string $etag = null, ?int $id = null): void {
		if (!$node->isReadable()) { throw new ToolFailure(\OCA\Mcp\Platform\FileAccess\CommonMessages::notFound()); }
		$this->visibility->assertVisible($node);
		BackupWriteBoundary::check($path, $this->backups);
		NodeAccess::checkEtag($node, $etag);
		if ($id !== null && (int)$node->getId() !== $id) { throw new ToolFailure(\OCA\Mcp\Platform\FileAccess\CommonMessages::conflict()); }
		if (!$node->isDeletable()) { throw new ToolFailure(\OCA\Mcp\Platform\FileAccess\CommonMessages::forbidden()); }
	}

}
