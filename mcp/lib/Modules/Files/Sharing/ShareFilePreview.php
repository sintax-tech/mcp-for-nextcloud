<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Sharing;

use OCP\Files\Folder;
use OCA\Mcp\Modules\Files\FilesPreviewContext;

/** Preserves the existing Files ShareFilePreview behavior. */
final class ShareFilePreview {
	/**
	 * @param FilesPreviewContext $context existing context collaborator
	 * @param ?ShareWriter $shareWriter existing shareWriter collaborator
	 */
	public function __construct(
		private FilesPreviewContext $context,
		private ?ShareWriter $shareWriter = null,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged read-only plan
	 */
	public function plan(array $arguments, string $userId): array {
		$etag = $arguments['etag'] ?? null;
		return $this->context->run($userId, fn (Folder $root): array => ($this->shareWriter ?? throw new \LogicException('ShareWriter is not wired'))->plan($root, $userId, $arguments));
	}
}
