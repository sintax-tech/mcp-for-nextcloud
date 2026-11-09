<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Images;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCA\Mcp\Modules\Files\Presentation\FilesMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/** Normalizes image search and preserves its scope and exception boundaries. */
final class ImageSearchHandler {
	/**
	 * @param ImageTagSearch $tags System-tag image search with visibility filtering.
	 * @param ImageQuerySearch $query Filename, folder and date image search.
	 * @param LoggerInterface $logger Safe diagnostics for failed image operations.
	 * @param ?FilesAccessGuard $visibilityGuard Optional resource-scope visibility guard.
	 */
	public function __construct(
		private ImageTagSearch $tags,
		private ImageQuerySearch $query,
		private LoggerInterface $logger,
		private ?FilesAccessGuard $visibilityGuard = null,
	) {
	}

	/**
	 * Searches images by name, folder, mtime range and system tag; returns metadata only.
	 *
	 * @param Folder $root the user's folder
	 * @param string $userId authenticated user
	 * @param array{query?:string, folder?:string, modified_after?:string, modified_before?:string, tag?:string, limit:int} $args Validated tool arguments.
	 * @param \Closure(Folder, string, File): array<string, mixed> $entry Formats a visible image entry with actor access metadata.
	 * @return list<array<string, mixed>>
	 * @throws ToolFailure For an invalid folder, date, unresolved tag or backend failure.
	 * @throws PolicyUnavailable When the visibility policy cannot be read.
	 * @throws LockedException When Nextcloud reports a locked resource.
	 */
	public function execute(Folder $root, string $userId, array $args, \Closure $entry): array {
		$limit = max(1, min(ImageLimits::SEARCH_MAX_LIMIT, (int)$args['limit']));
		$query = isset($args['query']) ? trim((string)$args['query']) : '';
		$folder = isset($args['folder']) ? PathGuard::normalize((string)$args['folder'], 'folder') : '/';
		$after = $this->parseDate($args['modified_after'] ?? null);
		$before = $this->parseDate($args['modified_before'] ?? null);
		$tag = isset($args['tag']) && trim((string)$args['tag']) !== '' ? trim((string)$args['tag']) : null;

		// A hidden folder answers like a missing one: an empty list would tell that it is there.
		$scope = NodeAccess::getBase($root, $folder, $this->visibilityGuard);
		if (!$scope instanceof Folder) {
			throw new ToolFailure(FilesMessages::notAFolder());
		}

		try {
			if ($tag !== null) {
				return $this->tags->execute($root, $scope, $userId, $tag, $query, $after, $before, $limit, $entry);
			}
			return $this->query->execute($root, $scope, $userId, $query, $after, $before, $limit, $entry);
		} catch (ToolFailure|PolicyUnavailable|LockedException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->error('Image search failed: {tool}: {exception_detail}', [
				'app' => 'mcp',
				'tool' => 'files_image_search',
				'exception_class' => $e::class,
				'exception_detail' => ToolRegistry::loggable($e),
			]);
			throw new ToolFailure(ImageSearchMessages::failed(), 0, $e);
		}
	}

	/**
	 * @param mixed $raw ISO 8601 string or null
	 * @return int|null epoch seconds, or null when $raw is null
	 * @throws ToolFailure when $raw is set but not a parseable ISO 8601 moment
	 */
	private function parseDate(mixed $raw): ?int {
		if ($raw === null || $raw === '') {
			return null;
		}
		$value = (string)$raw;
		$time = strtotime($value);
		if ($time === false) {
			throw new ToolFailure(\OCA\Mcp\Modules\Files\Presentation\ImageMessages::invalidDate($value));
		}
		return $time;
	}
}
