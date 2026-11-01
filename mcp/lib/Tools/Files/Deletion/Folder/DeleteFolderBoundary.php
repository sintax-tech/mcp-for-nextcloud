<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tools\Files\Deletion\Folder;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Access\ResourceScope\FilesScope;
use OCA\Mcp\Tools\Files\FolderExposureGate;
use OCA\Mcp\Tools\Files\ReorganizationLimits;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;

/** Complete bounded admission of a personal folder tree; no partial listing becomes permission to delete. */
class DeleteFolderBoundary {
	/** @var int Most inspected nodes including the source, aligned with the existing recursive-copy budget. */
	public int $nodeLimit = ReorganizationLimits::NODES;
	/** @var int Most file bytes, counted once, aligned with the existing copy ceiling. */
	public int $byteLimit = ReorganizationLimits::BYTES;
	/** @var int Greatest accepted child depth; the source has depth zero. */
	public int $depthLimit = 64;

	/**
	 * @param FilesAccessGuard $visibility Current operation's scope/Guests/exclusion gate.
	 * @param FilesScope $scope Selected resource identities, including nested selections.
	 */
	public function __construct(private FilesAccessGuard $visibility, private FilesScope $scope) {
	}

	/**
	 * Refuses structural boundaries before any directory listing or recursive lock helper runs.
	 * @param Folder $root Actor's personal root.
	 * @param string $uid Actor of the open policy operation.
	 * @param Folder $folder Already visible source.
	 * @return void
	 */
	public function assertSource(Folder $root, string $uid, Folder $folder): void {
		FolderExposureGate::assertExposure($folder, $this->visibility);
		$this->assertBoundary($root, $folder, $this->scope->items($uid));
	}

	/**
	 * Inspects every admitted node and returns only a call-local observation and public aggregate counts.
	 * Native getDirectoryListing has no limit; one wide directory is materialized before its size can be refused.
	 * @param Folder $root Actor's root.
	 * @param string $uid Actor of the open policy operation.
	 * @param Folder $folder Source; no mount/share roots can enter the walk.
	 * @param callable(Node):void $admit Common native/scope/hidden/backup/share/backend admission for each node.
	 * @return array{fingerprint:string,nodes:int,files:int,folders:int,bytes:int} Complete observed metadata, never an approval token.
	 */
	public function inspect(Folder $root, string $uid, Folder $folder, callable $admit): array {
		$this->assertSource($root, $uid, $folder);
		$selected = $this->scope->items($uid);
		$queue = new \SplQueue();
		$queue->enqueue([$folder, 0]);
		$seen = [];
		$entries = [];
		$files = $folders = $bytes = 0;
		try {
			while (!$queue->isEmpty()) {
				[$node, $depth] = $queue->dequeue();
				if (count($entries) >= $this->nodeLimit || $depth > $this->depthLimit) {
					throw new \RuntimeException('Walk limit');
				}
				$admit($node);
				$id = (int)$node->getId();
				$path = $node->getPath();
				if ($id <= 0 || isset($seen[$id])) {
					throw new \RuntimeException('Unresolved or repeated identity');
				}
				$seen[$id] = true;
				$isFolder = $node instanceof Folder;
				$size = $isFolder ? 0 : $node->getSize(false);
				if ($size < 0 || $bytes + $size > $this->byteLimit) {
					throw new \RuntimeException('Byte limit or unknown size');
				}
				$bytes += $size;
				$entries[$path] = [$id, (string)$node->getEtag(), $isFolder, $size];
				if (!$isFolder) {
					$files++;
					continue;
				}
				$folders++;
				$this->assertBoundary($root, $node, $selected);
				$children = $node->getDirectoryListing();
				if (count($children) > $this->nodeLimit - count($entries) - $queue->count()) {
					throw new \RuntimeException('Incomplete listing budget');
				}
				foreach ($children as $child) {
					if (!$child instanceof Node || !str_starts_with($child->getPath(), rtrim($path, '/') . '/')
						|| dirname($child->getPath()) !== $path) {
						throw new \RuntimeException('Unresolved child');
					}
					$queue->enqueue([$child, $depth + 1]);
				}
			}
		} catch (\Throwable) {
			// No child names, counts or failure details escape a refused/incomplete inspection.
			throw new ToolFailure(DeleteFolderMessages::refused());
		}
		ksort($entries, SORT_STRING);
		return ['fingerprint' => hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR)),
			'nodes' => count($entries), 'files' => $files, 'folders' => $folders, 'bytes' => $bytes];
	}

	/**
	 * Checks every folder so a selected or mounted child cannot be destroyed through its ancestor.
	 * @param Folder $root Actor's personal root.
	 * @param Folder $folder Current candidate.
	 * @param list<string>|null $selected All selected scope identities, before list-root filtering.
	 * @return void
	 */
	private function assertBoundary(Folder $root, Folder $folder, ?array $selected): void {
		if ($folder->getPath() === $root->getPath() || $folder->isMounted()
			|| rtrim((string)$folder->getMountPoint()->getMountPoint(), '/') === rtrim($folder->getPath(), '/')
			|| in_array(FilesScope::canonical($folder->getId()), $selected ?? [], true)) {
			throw new ToolFailure(DeleteFolderMessages::refused());
		}
	}
}
