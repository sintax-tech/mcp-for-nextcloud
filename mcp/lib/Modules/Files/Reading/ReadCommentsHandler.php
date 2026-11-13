<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reading;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Files\FilesCallContext;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

/**
 * Reads one page of the comments of a visible file or folder through the native comments REPORT.
 *
 * The path is resolved in the actor's own view under the Files gate; whatever stops it there (missing, hidden,
 * unreadable) is answered like a missing file, the same answer {@see FileMetadataDav} gives for a file the native
 * comments entity does not resolve. The page is the native offset/limit page, newest first: offset counts from the
 * newest comment, so a comment added between two calls moves the following pages by one. Only a REPORT is sent,
 * which reads the reader's marker (CommentNode::getProperties) and never writes it.
 */
final class ReadCommentsHandler {
	/** Page size when the caller gives none. */
	public const DEFAULT_LIMIT = 20;

	/**
	 * @param FilesCallContext $context actor-root admission and node-access mapping
	 * @param FilesAccessGuard $guard Files scope, Guests external storage, exclusion tags and backups
	 * @param FileMetadataDav $dav native comments REPORT as the authenticated actor
	 * @param CommentFeed $feed reads the native multistatus answer
	 */
	public function __construct(
		private FilesCallContext $context,
		private FilesAccessGuard $guard,
		private FileMetadataDav $dav,
		private CommentFeed $feed,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated tool arguments: path, optional limit and offset
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> the page: path, order, offset, limit, next_offset and the comments
	 */
	public function handle(array $arguments, string $userId): array {
		$path = PathGuard::normalize((string)$arguments['path']);
		$limit = (int)($arguments['limit'] ?? self::DEFAULT_LIMIT);
		$offset = (int)($arguments['offset'] ?? 0);
		return $this->context->run($userId, function (Folder $root) use ($path, $limit, $offset, $userId): array {
			try {
				$id = (int)NodeAccess::get($root, $path, $this->guard)->getId();
			} catch (ToolFailure|NotFoundException|NotPermittedException) {
				throw new ToolFailure(CommonMessages::notFound());
			}
			$comments = $this->feed->comments((string)$this->dav->comments($userId, $id, $limit, $offset)->body);
			return ToolResult::json([
				'path' => $path,
				'order' => 'newest_first',
				'offset' => $offset,
				'limit' => $limit,
				'next_offset' => count($comments) === $limit ? $offset + $limit : null,
				'comments' => $comments,
			]);
		});
	}
}
