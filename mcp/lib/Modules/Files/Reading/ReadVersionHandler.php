<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reading;

use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\Files\Folder;
use OCA\Mcp\Modules\Files\FilesCallContext;
use OCA\Mcp\Modules\Files\VersionTools;

/** Runs the operation that reads historical file text with version metadata inside the actor view. */
final class ReadVersionHandler {
	/**
	 * @param FilesCallContext $context actor-root admission and node-access mapping
	 * @param VersionTools $versions version listing and content access
	 * @param ReadableFile $readable visible file resolver
	 */
	public function __construct(
		private FilesCallContext $context,
		private VersionTools $versions,
		private ReadableFile $readable,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged tool result
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->context->run($userId, fn (Folder $root): array => ToolResult::json($this->versions->read($this->readable->get($root, $arguments['path']), PathGuard::normalize($arguments['path']), $arguments['version'], $userId)));
	}
}
