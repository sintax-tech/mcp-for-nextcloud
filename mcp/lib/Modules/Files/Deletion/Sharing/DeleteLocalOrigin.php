<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Files\Deletion\Sharing;

use OCA\Mcp\Platform\Backups\BackupLocation;
use OCA\Mcp\Modules\Files\BackupWriteBoundary;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\IHomeStorage;
use OCP\Files\Storage\ISharedStorage;

/** Resolves metadata by public OCP identity; the owner's node is never used as the actor's deletion authority. */
final class DeleteLocalOrigin {
	/** @param BackupLocation $backups Fresh current/historical reservations. @param IRootFolder|null $roots Public owner-tree lookup, required for received shares. */
	public function __construct(private BackupLocation $backups, private ?IRootFolder $roots = null) {
	}

	/**
	 * Resolves a unique canonical Local origin without borrowing the owner's deletion authority.
	 *
	 * @param Folder $actorRoot Acting user's personal root.
	 * @param string $actor Authenticated actor.
	 * @param Node $node Visible/readable actor node; native DELETE is checked separately on this node.
	 * @return array{node:Node,root:Folder,owner:string,received:bool} Metadata-only canonical Local origin.
	 */
	public function resolve(Folder $actorRoot, string $actor, Node $node): array {
		if ($node->isMounted() || rtrim($node->getMountPoint()->getMountPoint(), '/') === rtrim($node->getPath(), '/')) {
			throw new \RuntimeException('Mount root');
		}
		$owner = $node->getOwner()?->getUID();
		if (!is_string($owner) || $owner === '' || (int)$node->getId() <= 0) { throw new \RuntimeException('Unknown owner'); }
		$received = $node->getStorage()->instanceOfStorage(ISharedStorage::class);
		if (!$received) {
			if ($owner !== $actor) { throw new \RuntimeException('Foreign non-share storage'); }
			$this->assertLocal($node, $owner);
			return ['node' => $node, 'root' => $actorRoot, 'owner' => $owner, 'received' => false];
		}
		if ($this->roots === null) { throw new \RuntimeException('Owner origin unavailable'); }
		$root = $this->roots->getUserFolder($owner);
		$candidates = [];
		foreach ($root->getById((int)$node->getId()) as $origin) {
			if (!$origin instanceof Node || $origin->getId() !== $node->getId()) { throw new \RuntimeException('Unresolved alias'); }
			$relative = $root->getRelativePath($origin->getPath());
			if (!is_string($relative)) { throw new \RuntimeException('Unresolved origin path'); }
			BackupWriteBoundary::check($relative, $this->backups);
			if (!$origin->getStorage()->instanceOfStorage(ISharedStorage::class)
				&& $origin->getStorage()->instanceOfStorage(IHomeStorage::class)
				&& $origin->getStorage()->instanceOfStorage('OC\\Files\\Storage\\Local')) {
				$this->assertLocal($origin, $owner);
				$candidates[$origin->getPath()] = $origin;
			}
		}
		if (count($candidates) !== 1) { throw new \RuntimeException('No unique Local origin'); }
		$origin = array_values($candidates)[0];
		if (($origin instanceof Folder) !== ($node instanceof Folder)) { throw new \RuntimeException('Origin type changed'); }
		return ['node' => $origin, 'root' => $root, 'owner' => $owner, 'received' => true];
	}

	/**
	 * Refuses unsupported canonical storage and owner boundaries.
	 *
	 * @param Node $node Canonical owner node or ancestor; not an actor permission check.
	 * @param string $owner Expected local owner.
	 * @return void
	 */
	public function assertLocal(Node $node, string $owner): void {
		$storage = $node->getStorage();
		if (!$storage->instanceOfStorage(IHomeStorage::class) || !$storage->instanceOfStorage('OC\\Files\\Storage\\Local')
			|| $storage->instanceOfStorage(ISharedStorage::class) || $node->getOwner()?->getUID() !== $owner || $node->isMounted()
			|| rtrim($node->getMountPoint()->getMountPoint(), '/') === rtrim($node->getPath(), '/')) {
			throw new \RuntimeException('Unsupported canonical storage');
		}
	}
}
