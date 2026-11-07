<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reading;

use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\Files\Folder;
use OCA\Mcp\Modules\Files\FilesCallContext;
use OCA\Mcp\Modules\Files\Images\ImageTools;

/** Runs the operation that searches image candidates through the image service inside the actor view. */
final class SearchImagesHandler {
	/**
	 * @param FilesCallContext $context actor-root admission and node-access mapping
	 * @param ImageTools $images image viewing and search operations
	 */
	public function __construct(
		private FilesCallContext $context,
		private ImageTools $images,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged tool result
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->context->run($userId, fn (Folder $root): array => ToolResult::json($this->images->search($root, $userId, $arguments)));
	}
}
