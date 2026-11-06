<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Editing;

use OCA\Mcp\Modules\Files\Handler\RestoreVersionHandler;
use OCP\Files\Folder;
use OCA\Mcp\Modules\Files\FilesCallContext;
use OCA\Mcp\Modules\Files\VersionTools;

/** Runs the operation that restores historical content through the guarded restore handler inside the actor view. */
final class RestoreVersionAction {
	/**
	 * @param FilesCallContext $context actor-root admission and node-access mapping
	 * @param VersionTools $versions version listing and content access
	 * @param ?RestoreVersionHandler $restoreVersion optional guarded version-restore handler
	 */
	public function __construct(
		private FilesCallContext $context,
		private VersionTools $versions,
		private ?RestoreVersionHandler $restoreVersion = null,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged tool result
	 */
	public function handle(array $arguments, string $userId): array {
		$confirmed = (bool)($arguments['confirm_shared'] ?? false);
		return $this->context->run($userId, fn (Folder $root): array => ($this->restoreVersion ?? throw new \LogicException('RestoreVersionHandler is not wired'))->execute($root, $userId, $arguments['path'], $arguments['version'], $confirmed, $this->versions));
	}
}
