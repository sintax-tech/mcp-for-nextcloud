<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service;

use OCA\Mcp\Tools\Files\FileBackup;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\Storage\ISharedStorage;

/** Locates backup mirrors only under the personal root that receives pre-write copies. */
final class BackupFolderMatcher {
	/** @var array<string, list<array{Folder, list<string>}>> verified candidate locations for this request */
	private array $candidates = [];

	/**
	 * Drops candidate metadata after filesystem, tag or configuration changes.
	 *
	 * @return void
	 */
	public function clearCache(): void {
		$this->candidates = [];
	}

	/**
	 * @param IRootFolder|null $rootFolder filesystem root for resolving owner aliases and mount boundaries
	 * @param string $folderName direct child name reserved for backups
	 */
	public function __construct(private ?IRootFolder $rootFolder = null, private string $folderName = FileBackup::FOLDER) {
	}

	/**
	 * Resolves named backup candidates in the owner's tree when encountered through another user's share.
	 * Other storages use their mounted personal root; their owner metadata need not identify a local user.
	 *
	 * @param Node $node node to classify
	 * @param callable(Node): Node $parentOf mount-aware parent resolver
	 * @return list<array{Folder, list<string>}> personal roots and mirror path segments
	 * @throws \Throwable when ancestry or a local share origin cannot be resolved safely
	 */
	public function matches(Node $node, callable $parentOf): array {
		return array_values(array_filter($this->inTree($node, $parentOf), static fn (array $match): bool => $match[1] !== []));
	}

	/**
	 * Finds the outer backup folder, ignoring identically named folders deeper in the tree.
	 * The canonical /uid/files path also supports callers without an injected filesystem root.
	 *
	 * @param Node $node node in one user's mounted tree
	 * @param callable(Node): Node $parentOf mount-aware parent resolver
	 * @param array<string, true> $resolving owner aliases already being resolved
	 * @return list<array{Folder, list<string>}> roots and segments below each backup alias
	 * @throws \Throwable when a candidate's personal root cannot be verified
	 */
	private function inTree(Node $node, callable $parentOf, array $resolving = []): array {
		$key = $node->getId() . ':' . $node->getPath();
		if (isset($resolving[$key])) {
			throw new \RuntimeException('Cyclic backup origin');
		}
		$resolving[$key] = true;
		$current = $node;
		$segments = [];
		$seen = [];
		while (!$current instanceof IRootFolder) {
			$id = (int)$current->getId();
			if ($id <= 0 || isset($seen[$id])) {
				throw new \RuntimeException('Backup ancestry unavailable');
			}
			$seen[$id] = true;
			$parent = $parentOf($current);
			if ($current instanceof Folder && $current->getName() === $this->folderName
				&& $parent instanceof Folder && preg_match('~^/([^/]+)/files(?:/|$)~', $current->getPath(), $homePath)) {
				$key = $id . ':' . $current->getPath();
				$matches = $this->candidates[$key] ??= $this->candidate($current, $parent, $homePath[1], $parentOf, $resolving);
				if ($matches !== []) {
					return array_map(static fn (array $match): array => [$match[0], [...$match[1], ...$segments]], $matches);
				}
			}
			array_unshift($segments, $current->getName());
			$current = $parent;
		}
		return [];
	}

	/**
	 * Resolves a named candidate once, using local-share identity through the storage wrapper contract.
	 *
	 * @param Folder $candidate named folder being classified
	 * @param Folder $parent verified mounted parent
	 * @param string $mountedUser uid from the mounted path
	 * @param callable(Node): Node $parentOf mount-aware parent resolver
	 * @param array<string, true> $resolving owner aliases already being resolved
	 * @return list<array{Folder, list<string>}> canonical backup locations, excluding descendant suffixes
	 * @throws \Throwable when local share identity, origins or the personal root cannot be verified
	 */
	private function candidate(Folder $candidate, Folder $parent, string $mountedUser, callable $parentOf, array $resolving): array {
		if ($candidate->getStorage()->instanceOfStorage(ISharedStorage::class)) {
			$owner = $candidate->getOwner();
			if ($owner === null || $owner->getUID() === '' || $this->rootFolder === null) {
				throw new \RuntimeException('Backup owner unavailable');
			}
			if ($owner->getUID() !== $mountedUser) {
				$home = $this->rootFolder->getUserFolder($owner->getUID());
				$id = (int)$candidate->getId();
				$origins = $home->getById($id);
				if ($origins === []) {
					throw new \RuntimeException('Backup origin unavailable');
				}
				$matches = [];
				foreach ($origins as $origin) {
					if (!$origin instanceof Node || (int)$origin->getId() !== $id || $origin->getPath() === $candidate->getPath()) {
						throw new \RuntimeException('Invalid backup origin');
					}
					array_push($matches, ...$this->inTree($origin, $parentOf, $resolving));
				}
				return $matches;
			}
		}
		if ($parent->getPath() !== '/' . $mountedUser . '/files') {
			return [];
		}
		if ($this->rootFolder !== null) {
			$home = $this->rootFolder->getUserFolder($mountedUser);
			if ($home->getPath() !== $parent->getPath() || (int)$home->getId() !== (int)$parent->getId()) {
				throw new \RuntimeException('Backup root unavailable');
			}
			$parent = $home;
		}
		return [[$parent, []]];
	}
}
