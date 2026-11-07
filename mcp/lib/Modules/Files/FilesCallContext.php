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

/** Runs confirmed operations after scope admission and full actor setup, mapping native node errors. */
final class FilesCallContext {
	/**
	 * @param IRootFolder $rootFolder native root provider for the actor view
	 * @param ?FilesAccessGuard $visibilityGuard optional resource-scope admission guard
	 * @param ?Access\ActorRoot $actorRoot optional actor filesystem setup
	 */
	public function __construct(
		private IRootFolder $rootFolder,
		private ?FilesAccessGuard $visibilityGuard = null,
		private ?Access\ActorRoot $actorRoot = null,
	) {
	}

	/**
	 * @param string $userId authenticated caller
	 * @param callable(Folder):array $callback operation over the admitted actor folder
	 * @return array<string,mixed> unchanged operation result
	 */
	public function run(string $userId, callable $callback): array {
		$this->visibilityGuard?->assertScopeReadable();
		$root = $this->actorRoot?->get($userId) ?? $this->rootFolder->getUserFolder($userId);
		return NodeAccess::run(fn (): array => $callback($root));
	}
}
