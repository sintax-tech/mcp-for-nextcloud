<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tagging;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Files\FilesCallContext;
use OCA\Mcp\Modules\Files\Reading\FileMetadataDav;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCP\Files\Folder;
use OCP\Files\ForbiddenException;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

/**
 * Lists the system tags of one visible file or folder, read through the native DAV relation as the actor.
 *
 * The path resolves inside the actor's root behind the Files gate; then {@see FileMetadataDav::tags()} admits the file
 * ID again on every alias and lets the native collection decide which tags the actor may see. A hidden, missing or
 * unreadable path, and a file DAV refuses, all give the same not-found refusal. Nothing is written.
 */
final class ListTagsHandler {
	/**
	 * @param FilesCallContext $context actor-root admission and node-error mapping
	 * @param FilesAccessGuard $guard Files gate of the path and source of the MCP exclusion tag IDs
	 * @param FileMetadataDav $dav native DAV read of the file's tag relation
	 */
	public function __construct(
		private FilesCallContext $context,
		private FilesAccessGuard $guard,
		private FileMetadataDav $dav,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> the path and its visible tags
	 */
	public function handle(array $arguments, string $userId): array {
		$path = PathGuard::normalize((string)$arguments['path']);
		return $this->context->run($userId, function (Folder $root) use ($path, $userId): array {
			$answer = $this->dav->tags($userId, $this->fileId($root, $path));
			return ToolResult::json(['path' => $path, 'tags' => FileTagList::parse((string)$answer->body, $this->guard->getHiddenTagIds())]);
		});
	}

	/**
	 * @param Folder $root the actor's root
	 * @param string $path normalized user-relative path
	 * @return int ID of the visible, readable node at that path
	 * @throws ToolFailure the not-found refusal for a hidden, missing, unreadable or forbidden path
	 */
	private function fileId(Folder $root, string $path): int {
		try {
			return (int)NodeAccess::get($root, $path, $this->guard)->getId();
		} catch (ToolFailure|NotFoundException|NotPermittedException|ForbiddenException) {
			throw new ToolFailure(CommonMessages::notFound());
		}
	}
}
