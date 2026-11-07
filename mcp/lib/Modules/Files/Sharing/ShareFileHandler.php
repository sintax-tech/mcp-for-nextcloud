<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Sharing;

use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\Files\Folder;
use OCA\Mcp\Modules\Files\FilesCallContext;

/** Runs the operation that creates or updates an actor-owned share inside the actor view. */
final class ShareFileHandler {
	/**
	 * @param FilesCallContext $context actor-root admission and node-access mapping
	 * @param ?ShareWriter $shareWriter optional share creation and update service
	 */
	public function __construct(
		private FilesCallContext $context,
		private ?ShareWriter $shareWriter = null,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged tool result
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->context->run($userId, fn (Folder $root): array => self::shareResult(($this->shareWriter ?? throw new \LogicException('ShareWriter is not wired'))->apply($root, $userId, $arguments)));
	}

	/**
	 * The result of files_share in text and in structuredContent, so a client that shows either one shows the generated
	 * link password, which this result is the only place to carry.
	 *
	 * @param array<string, mixed> $result what {@see ShareWriter::apply()} returned
	 * @return array{content: list<array{type:string, text:string}>, structuredContent: array<string, mixed>}
	 */
	private static function shareResult(array $result): array {
		return ToolResult::structured(ToolResult::json($result)['content'][0]['text'], $result);
	}
}
