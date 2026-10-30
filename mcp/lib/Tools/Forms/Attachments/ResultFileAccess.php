<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Attachments;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\Scope\FilesAccessGuard;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IURLGenerator;

/** Projects only files readable through every alias in the authenticated actor's tree. */
class ResultFileAccess {
	/** Receives official actor-relative Files access, never a form-owner root.
	 * @param GrantPolicy $policy Existing operation's policy.
	 * @param FilesAccessGuard $guard Scope/Guests/hidden/owner-chain boundary.
	 * @param IRootFolder $root Native caller tree.
	 * @param IURLGenerator $urls Existing native viewer route.
	 */
	public function __construct(private GrantPolicy $policy, private FilesAccessGuard $guard, private IRootFolder $root, private IURLGenerator $urls) {
	}
	/** Checks Files grant independently; an unavailable policy is never treated as absence.
	 * @param string $uid Actor within the current policy operation.
	 * @return bool Whether file associations may be looked up.
	 */
	public function begin(string $uid): bool {
		if (!$this->policy->granted($uid, 'files', 'read')) { return false; }
		$this->guard->clearCache(); $this->guard->assertScopeReadable();
		return true;
	}
	/** Omits inaccessible associations uniformly; unexpected errors propagate instead of hiding outages.
	 * @param string $uid Authenticated actor.
	 * @param int $fileId Native answer reference, already tied to an active question.
	 * @return array|null Authorized native viewer metadata, or uniform omission.
	 */
	public function resolve(string $uid, int $fileId): ?array {
		if ($fileId <= 0) { return null; }
		try { $nodes = $this->root->getUserFolder($uid)->getById($fileId); }
		catch (NotFoundException) { return null; }
		if ($nodes === []) { return null; }
		foreach ($nodes as $node) {
			if (!$node instanceof File || (int)$node->getId() !== $fileId || !$node->isReadable() || !$this->guard->isVisible($node)) { return null; }
		}
		usort($nodes, static fn (File $a, File $b): int => $a->getPath() <=> $b->getPath());
		$node = $nodes[0];
		return ['fileId' => $fileId, 'name' => $node->getName(), 'size' => (int)$node->getSize(), 'mimeType' => $node->getMimetype(),
			'url' => $this->urls->linkToRouteAbsolute('files.View.showFile', ['fileid' => $fileId])];
	}
}
