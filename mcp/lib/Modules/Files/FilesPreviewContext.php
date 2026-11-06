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

/** Runs plans through the lazy actor view and maps native node errors. */
final class FilesPreviewContext {
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
	 * Plans apply their existing admission checks to a lazy native view, not necessarily the view of call().
	 * Only a native NotFound that reaches PreviewRoot before NodeAccess mapping permits full actor setup
	 * and one plan repetition. FileCreation::folder maps its miss internally to ToolFailure; planners using
	 * nodeExists may instead see false. Neither result triggers that fallback, nor does a policy/ACL refusal.
	 * With warm setup and an unmounted received share, a preview can therefore refuse the path or predict
	 * different parents/shared impact than call(). The confirmed call performs full setup and reapplies its
	 * checks, including confirm_shared. These divergences are source-derived limits, not runtime reproductions.
	 * The plan itself creates no backup, folder or checkout token. Native lazy root setup can still normalize
	 * received mount targets and their configured share folder, particularly with cold setup state or after
	 * a genuinely missing path reaches the fallback. No universal absence of platform effects is promised.
	 *
	 * @param string $userId authenticated caller
	 * @param callable(Folder):array $callback operation over the admitted actor folder
	 * @return array<string,mixed> unchanged operation result
	 */
	public function run(string $userId, callable $callback): array {
		$this->visibilityGuard?->assertScopeReadable();
		return NodeAccess::run(fn (): array => (new Access\PreviewRoot($this->rootFolder, $this->actorRoot))->run($userId, $callback));
	}
}
