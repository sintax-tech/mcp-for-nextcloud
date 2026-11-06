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

/** Preserves the existing Files RestoreVersionPreview behavior. */
final class RestoreVersionPreview {
	/**
	 * @param FilesPreviewContext $context existing context collaborator
	 * @param VersionTools $versions existing versions collaborator
	 * @param ?RestoreVersionHandler $restoreVersion existing restoreVersion collaborator
	 * @param ?UserTimezone $timezone existing timezone collaborator
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
		$etag = $arguments['etag'] ?? null;
		return $this->context->run($userId, fn (Folder $root): array => ($this->restoreVersion ?? throw new \LogicException('RestoreVersionHandler is not wired'))->plan($root, $userId, $arguments['path'], $arguments['version'], $this->versions, $this->timezone));
	}
}
