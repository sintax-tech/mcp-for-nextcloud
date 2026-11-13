<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reading;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCA\Mcp\Platform\Meetings\LocalInstanceLink;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IURLGenerator;

/**
 * Resolves a file id or a file link to one path in the actor's tree.
 *
 * Every alias has to pass the scope and the exclusion, and one of them has to be a readable file.
 * A link is not followed beyond that lookup. A Deck card URL is not a file. An absolute URL is accepted only
 * when its host is this instance; a relative path is accepted as written.
 */
final class MeetingFileLocator implements MeetingFileLookup {
	/** Route of the canonical `/f/<id>` link. */
	private const FILE_ROUTE = 'files.View.showFile';

	/**
	 * @param IRootFolder $root the actor's files
	 * @param FilesAccessGuard $guard scope, aliases and tags
	 * @param LocalInstanceLink $local which absolute hosts belong to this instance
	 * @param IURLGenerator $urls the canonical file link
	 */
	public function __construct(
		private IRootFolder $root,
		private FilesAccessGuard $guard,
		private LocalInstanceLink $local,
		private IURLGenerator $urls,
	) {
	}

	/** {@inheritDoc} */
	public function path(string $userId, string $reference): ?string {
		$placed = $this->placed($userId, $reference);
		return $placed['path'] ?? null;
	}

	/** {@inheritDoc} */
	public function placed(string $userId, string $reference): ?array {
		if (!$this->local->accepts($reference)) {
			return null;
		}
		$parsed = $this->parse($reference);
		if ($parsed === null || ($parsed['uid'] !== null && $parsed['uid'] !== $userId)) {
			return null;
		}
		$this->guard->assertScopeReadable();
		try {
			$folder = $this->root->getUserFolder($userId);
			return $parsed['id'] !== null ? $this->fromId($folder, $parsed['id']) : $this->fromPath($folder, (string)$parsed['path']);
		} catch (PolicyUnavailable $unavailable) {
			throw $unavailable;
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * @param string $reference a file id, a URL or a path
	 * @return array{id: int|null, path: string|null, uid: string|null}|null the shape, or null when it is not a file
	 */
	private function parse(string $reference): ?array {
		$reference = rtrim(trim($reference), ".,);]>'\" ");
		if ($reference === '' || str_starts_with(strtolower($reference), 'data:') || preg_match('~/apps/deck/(?:#/)?board/\d+/card/\d+~', $reference) === 1) {
			return null;
		}
		if (ctype_digit($reference)) {
			return ['id' => (int)$reference, 'path' => null, 'uid' => null];
		}
		$parts = parse_url($reference);
		if ($parts === false) {
			return null;
		}
		$path = $this->marker((string)($parts['path'] ?? ''));
		$query = [];
		parse_str((string)($parts['query'] ?? ''), $query);
		if (preg_match('#^/(?:f|apps/files/files)/(\d+)/?$#', $path, $match) === 1) {
			return ['id' => (int)$match[1], 'path' => null, 'uid' => null];
		}
		if (preg_match('#^/remote\.php/dav/files/([^/]+)(/.*)?$#', $path, $match) === 1) {
			return ['id' => null, 'path' => rawurldecode($match[2] ?? '/'), 'uid' => rawurldecode($match[1])];
		}
		if (preg_match('#^/remote\.php/webdav(/.*)?$#', $path, $match) === 1) {
			return ['id' => null, 'path' => rawurldecode($match[1] ?? '/'), 'uid' => null];
		}
		if (preg_match('#^/apps/files(?:/|$)#', $path) === 1 && isset($query['fileid']) && is_string($query['fileid']) && ctype_digit($query['fileid'])) {
			return ['id' => (int)$query['fileid'], 'path' => null, 'uid' => null];
		}
		if (!isset($parts['scheme']) && str_starts_with($reference, '/') && !str_starts_with($path, '/apps/') && !str_starts_with($path, '/remote.php')) {
			return ['id' => null, 'path' => $reference, 'uid' => null];
		}
		return null;
	}

	/**
	 * @param string $path a URL path, possibly under a web root or index.php
	 * @return string the path from the first file-shaped marker
	 */
	private function marker(string $path): string {
		$index = strpos($path, '/index.php/');
		if ($index !== false) {
			$path = substr($path, $index + strlen('/index.php'));
		}
		foreach (['/remote.php/', '/apps/files/', '/f/'] as $marker) {
			$at = strpos($path, $marker);
			if ($at !== false && $at > 0) {
				return substr($path, $at);
			}
		}
		return $path;
	}

	/**
	 * @param Folder $folder the actor's folder
	 * @param int $id the file id
	 * @return array{path: string, id: int, mtime: int, url: string}|null one readable file alias
	 */
	private function fromId(Folder $folder, int $id): ?array {
		if ($id <= 0) {
			return null;
		}
		$nodes = (int)$folder->getId() === $id ? [$folder] : $folder->getById($id);
		if ($nodes === []) {
			return null;
		}
		$chosen = null;
		foreach ($nodes as $node) {
			if (!$node instanceof Node || (int)$node->getId() !== $id || !$this->visible($node)) {
				return null;
			}
			if ($chosen === null && $node->isReadable() && !$this->isFolder($node)) {
				$chosen = $node;
			}
		}
		if ($chosen === null) {
			return null;
		}
		$path = $this->relative($folder, $chosen);
		return $path === null ? null : $this->describe($chosen, $path);
	}

	/**
	 * @param Folder $folder the actor's folder
	 * @param string $path a path in that folder
	 * @return array{path: string, id: int, mtime: int, url: string}|null the file when it is one readable file
	 */
	private function fromPath(Folder $folder, string $path): ?array {
		$normalized = PathGuard::normalize($path);
		if ($normalized === '/') {
			return null;
		}
		$node = $folder->get(ltrim($normalized, '/'));
		if (!$node instanceof Node || !$this->visible($node) || !$node->isReadable() || $this->isFolder($node)) {
			return null;
		}
		return $this->describe($node, $normalized);
	}

	/**
	 * @param Node $node the readable file
	 * @param string $path its user-relative path
	 * @return array{path: string, id: int, mtime: int, url: string} the file the dossier may quote
	 */
	private function describe(Node $node, string $path): array {
		$id = (int)$node->getId();
		return [
			'path' => $path,
			'id' => $id,
			'mtime' => (int)$node->getMTime(),
			'url' => $this->urls->linkToRouteAbsolute(self::FILE_ROUTE, ['fileid' => $id]),
		];
	}

	/**
	 * @param Node $node one alias
	 * @return bool whether scope and tags admit it
	 * @throws PolicyUnavailable when the scope cannot be read
	 */
	private function visible(Node $node): bool {
		return $this->guard->isVisible($node);
	}

	/**
	 * @param Node $node a resolved node
	 * @return bool whether it is a folder
	 */
	private function isFolder(Node $node): bool {
		return $node instanceof Folder || $node->getType() === FileInfo::TYPE_FOLDER;
	}

	/**
	 * @param Folder $folder the actor's folder
	 * @param Node $node a file under it
	 * @return string|null the user-relative path
	 */
	private function relative(Folder $folder, Node $node): ?string {
		$relative = $folder->getRelativePath($node->getPath());
		if (!is_string($relative) || $relative === '' || $relative === '/') {
			return null;
		}
		try {
			$path = PathGuard::normalize($relative);
		} catch (\InvalidArgumentException) {
			return null;
		}
		return $path === '/' ? null : $path;
	}
}
