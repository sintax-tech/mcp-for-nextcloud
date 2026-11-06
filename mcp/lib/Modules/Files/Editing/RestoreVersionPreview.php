<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Editing;

use OCA\Mcp\Modules\Files\Handler\RestoreVersionHandler;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCP\Files\Folder;
use OCA\Mcp\Modules\Files\FilesPreviewContext;
use OCA\Mcp\Modules\Files\VersionTools;

/** Plans the operation that restores historical content through the guarded restore handler, without executing the write. */
final class RestoreVersionPreview {
	/**
	 * @param FilesPreviewContext $context actor-root admission and node-access mapping
	 * @param VersionTools $versions version listing and content access
	 * @param ?RestoreVersionHandler $restoreVersion optional guarded version-restore handler
	 * @param ?UserTimezone $timezone optional user timezone formatter
	 */
	public function __construct(
		private FilesPreviewContext $context,
		private VersionTools $versions,
		private ?RestoreVersionHandler $restoreVersion = null,
		private ?UserTimezone $timezone = null,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged read-only plan
	 */
	public function plan(array $arguments, string $userId): array {
		return $this->context->run($userId, fn (Folder $root): array => ($this->restoreVersion ?? throw new \LogicException('RestoreVersionHandler is not wired'))->plan($root, $userId, $arguments['path'], $arguments['version'], $this->versions, $this->timezone));
	}
}
