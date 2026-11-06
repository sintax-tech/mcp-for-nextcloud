<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files;

use OCA\Mcp\Platform\FileAccess as Access;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;

/** Preserves the existing Files FilesPreviewContext behavior. */
final class FilesPreviewContext {
	/**
	 * @param IRootFolder $rootFolder existing rootFolder collaborator
	 * @param ?FilesAccessGuard $visibilityGuard existing visibilityGuard collaborator
	 * @param ?Access\ActorRoot $actorRoot existing actorRoot collaborator
	 */
	public function __construct(
		private IRootFolder $rootFolder,
		private ?FilesAccessGuard $visibilityGuard = null,
		private ?Access\ActorRoot $actorRoot = null,
	) {
	}

	/**
	 * @param string $userId authenticated caller
	 * @param callable(Folder):array $callback operation over the existing actor view
	 * @return array<string,mixed> unchanged operation result
	 */
	public function run(string $userId, callable $callback): array {
		$this->visibilityGuard?->assertScopeReadable();
		return NodeAccess::run(fn (): array => (new Access\PreviewRoot($this->rootFolder, $this->actorRoot))->run($userId, $callback));
	}
}
