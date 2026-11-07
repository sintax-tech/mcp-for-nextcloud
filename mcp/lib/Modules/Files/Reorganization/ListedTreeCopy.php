<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable;
use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;

/** B4 copies exactly the whole validated manifest, never a live directory; partial copies are never removed. */
final class ListedTreeCopy {
	/** @param CopyMoveContext $context Existing authorization, locking and policy services. */
	public function __construct(private \OCA\Mcp\Modules\Files\Reorganization\CopyMoveContext $context) {
	}

	/**
	 * @param Folder $root User root.
	 * @param string $userId Authenticated user.
	 * @param Node $source Existing source.
	 * @param string $to Normalized target.
	 * @param array<string,mixed>|null $manifest Whole preflight result, or off.
	 * @return array{node:Node|null,listed:bool,partial:bool,created:list<string>,conflicts:list<array{path:string,reason:string}>,not_copied:list<string>} Explicit complete/partial receipt.
	 */
	public function execute(Folder $root, string $userId, Node $source, string $to, ?array $manifest): array {
		if (!$source instanceof Folder) {
			$node = $this->native($root, $source, $this->context->absolute($root, $to), $userId, $to);
			return ['node' => $node, 'listed' => false, 'partial' => false, 'created' => [$to], 'conflicts' => [], 'not_copied' => []];
		}
		$snapshot = $this->context->policy()->snapshot();
		if ($manifest === null && $snapshot->isActive()) {
			$manifest = (new \OCA\Mcp\Modules\Files\Reorganization\FileTypePreflight($this->context))->inspect($source, $to, $snapshot, true);
		}
		if ($manifest === null) {
			$node = $this->native($root, $source, $this->context->absolute($root, $to), $userId, $to);
			return ['node' => $node, 'listed' => false, 'partial' => false, 'created' => [$to], 'conflicts' => [], 'not_copied' => []];
		}
		// Fresh complete check before the first effect, using only names from the original list.
		foreach ($manifest['entries'] as $entry) {
			if (!$entry['folder']) {
				$snapshot->assertWritableName($this->name($entry['to']));
			}
		}
		$created = $conflicts = $missing = [];
		$copiedRoot = null;
		$bytes = 0;
		$failedFolder = null;
		foreach ($manifest['entries'] as $index => $entry) {
			// DFS keeps descendants contiguous; skipping a failed subtree is linear, with no live lookup.
			if ($failedFolder !== null && str_starts_with($entry['to'], $failedFolder . '/')) {
				$missing[] = $entry['to'];
				continue;
			}
			$failedFolder = null;
			try {
				$node = $this->listed($root, $entry);
				$this->context->assertFree($root, $entry['to']);
				$parent = $this->context->destination($root, $entry['to']);
				if ($entry['folder']) {
					$this->context->policy()->snapshot();
					$copy = NodeAccess::mutate(function () use ($root, $entry, $parent): Folder {
						$this->listed($root, $entry);
						return $parent->newFolder($this->name($entry['to']));
					}, $this->context->visibilityGuard);
				} else {
					$bytes += max(0, (int)$node->getSize());
					if ($bytes > \OCA\Mcp\Modules\Files\Reorganization\ReorganizationLimits::BYTES) {
						throw new ToolFailure(\OCA\Mcp\Modules\Files\Presentation\ReorganizationMessages::copyTooLarge(\OCA\Mcp\Modules\Files\Reorganization\ReorganizationLimits::BYTES));
					}
					$copy = $this->native($root, $node, $this->context->absolute($root, $entry['to']), $userId, $entry['to'], $entry);
				}
				$this->context->visibilityGuard?->assertVisible($copy);
				$created[] = $entry['to'];
				if ($entry['to'] === $to) {
					$copiedRoot = $copy;
				}
			} catch (\Throwable $e) {
				$conflicts[] = ['path' => $entry['to'], 'reason' => $e instanceof ToolFailure ? $e->getMessage() : \OCA\Mcp\Modules\Files\Presentation\ReorganizationMessages::moveFailed()];
				$missing[] = $entry['to'];
				if ($created === []) {
					throw ($e instanceof ToolFailure ? $e : new ToolFailure(\OCA\Mcp\Modules\Files\Presentation\ReorganizationMessages::moveFailed()));
				}
				if ($bytes > \OCA\Mcp\Modules\Files\Reorganization\ReorganizationLimits::BYTES || $this->stopsCopy($e)) {
					$missing = array_merge($missing, array_column(array_slice($manifest['entries'], $index + 1), 'to'));
					break;
				}
				if ($entry['folder']) {
					$failedFolder = $entry['to'];
				}
			}
		}
		return ['node' => $copiedRoot, 'listed' => true, 'partial' => $conflicts !== [], 'created' => $created, 'conflicts' => $conflicts, 'not_copied' => $missing];
	}

	/**
	 * @param \Throwable $failure Failure raised at one copy boundary.
	 * @return bool Whether further entries must not acquire locks/read policy/attempt writes.
	 */
	private function stopsCopy(\Throwable $failure): bool {
		// NodeAccess::run maps quota exceptions to its safe message, without retaining the exception type.
		return $failure instanceof FileWritePolicyUnavailable
			|| $failure instanceof \OCP\Files\NotEnoughSpaceException
			|| !($failure instanceof ToolFailure)
			|| $failure->getMessage() === CommonMessages::insufficientQuota();
	}

	/**
	 * @param Folder $root User root, resolving the listed path afresh for this boundary.
	 * @param array{source:string,id:int,folder:bool} $entry Original manifest identity, never a substituted live name.
	 * @return Node The same identity and kind, still visible at the listed path.
	 * @throws ToolFailure When the listed item disappeared, was replaced, renamed or became hidden.
	 */
	private function listed(Folder $root, array $entry): Node {
		$relative = $root->getRelativePath($entry['source']);
		if ($relative === null) {
			throw new \OCA\Mcp\Modules\Files\Reorganization\MoveConflict(\OCA\Mcp\Modules\Files\Presentation\ReorganizationMessages::notTheBatchNode());
		}
		// A listed source that disappeared is an item conflict, not an unexpected global failure.
		$node = NodeAccess::run(fn () => NodeAccess::get($root, $relative, $this->context->visibilityGuard));
		if ($node->getPath() !== $entry['source'] || (int)$node->getId() !== $entry['id'] || ($node instanceof Folder) !== $entry['folder']) {
			throw new \OCA\Mcp\Modules\Files\Reorganization\MoveConflict(\OCA\Mcp\Modules\Files\Presentation\ReorganizationMessages::notTheBatchNode());
		}
		return $node;
	}

	/**
	 * @param string $path Normalized projected destination.
	 * @return string Last path segment, independent of process locale.
	 */
	private function name(string $path): string {
		$segments = PathGuard::segments($path);
		return $segments[array_key_last($segments)] ?? '';
	}

	/**
	 * @param Folder $root User root for a fresh listed-source resolution inside the sink callback.
	 * @param Node $source A file, or a folder only while policy is off at this boundary.
	 * @param string $absolute Absolute destination.
	 * @param string $userId Authenticated user.
	 * @param string $to User-relative final name.
	 * @param array{source:string,id:int,folder:bool}|null $identity Listed identity for the final check immediately at the sink.
	 * @return Node Result of the official node copy, preserving the existing lock-capture behavior.
	 */
	private function native(Folder $root, Node $source, string $absolute, string $userId, string $to, ?array $identity = null): Node {
		$copy = function () use ($root, $source, $absolute, $identity): Node {
			$current = $identity === null ? $source : $this->listed($root, $identity);
			return $current->copy($absolute);
		};
		$write = function () use ($source, $to, $copy): Node {
			if ($source instanceof Folder) {
				if ($this->context->policy()->snapshot()->isActive()) {
					// A policy enabled after an off preflight cannot fall through to native tree copy.
					throw new \OCA\Mcp\Access\FileTypes\FileTypeDenied();
				}
				return $copy();
			}
			return $this->context->policy()->write($source, $to, $copy);
		};
		return NodeAccess::mutate(fn () => $this->context->locks === null ? $write() : $this->context->locks->capture($write, $source, $userId), $this->context->visibilityGuard);
	}
}
