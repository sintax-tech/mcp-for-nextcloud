<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files\Access;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserSession;

/** Completes the native actor mount context before Files resolves paths, without listing file contents. */
final class ActorRoot {
	/**
	 * @param IRootFolder $roots Native root provider.
	 * @param IUserSession $session Real request session already bound by the MCP endpoint.
	 */
	public function __construct(private IRootFolder $roots, private IUserSession $session) {
	}

	/**
	 * Resolves only the bound actor's root and initializes its mounts through the public OCP API.
	 *
	 * Native ACL, scope, Guests, aliases and visibility checks still run on every target afterward.
	 * This neither enumerates child nodes nor promises cache invalidation or atomic revocation.
	 *
	 * @param string $uid Authenticated operation actor.
	 * @return Folder Native actor root.
	 * @throws ToolFailure When the session or native root does not match the actor.
	 */
	public function get(string $uid): Folder {
		$this->assertActor($uid);
		$root = $this->roots->getUserFolder($uid);
		$path = $root->getPath();
		if ($path !== '/' . $uid . '/files') {
			throw new ToolFailure(ToolFailure::NOT_FOUND);
		}
		// getMountsIn delegates to native setupForPath(path, true), completing the actor's mount providers.
		// Discard metadata: it is not a Files listing and conveys no authority to use any returned mount.
		$this->roots->getMountsIn($path);
		$this->assertActor($uid);
		return $root;
	}

	/**
	 * Resolves the canonical actor root without explicitly completing all mount providers.
	 *
	 * Native lazy lookup can still initialize mounts when its own setup state requires it.
	 *
	 * @param string $uid Authenticated preview actor.
	 * @return Folder Native lazy root, with the real session checked before and after lookup.
	 * @throws ToolFailure When identity or canonical root does not match the actor.
	 */
	public function lazy(string $uid): Folder {
		$this->assertActor($uid);
		$root = $this->roots->getUserFolder($uid);
		if ($root->getPath() !== '/' . $uid . '/files') {
			throw new ToolFailure(ToolFailure::NOT_FOUND);
		}
		$this->assertActor($uid);
		return $root;
	}

	/**
	 * Refuses stale or foreign request identity before a root lookup and after native provider hooks.
	 *
	 * @param string $uid Authenticated operation actor.
	 * @return void
	 * @throws ToolFailure When the request actor is unavailable or changed.
	 */
	private function assertActor(string $uid): void {
		if ($uid === '' || $this->session->getUser()?->getUID() !== $uid) {
			throw new ToolFailure(ToolFailure::NOT_FOUND);
		}
	}
}
