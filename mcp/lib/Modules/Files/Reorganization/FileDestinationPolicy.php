<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Access\FileTypes\FileTypeCheck;
use OCA\Mcp\Access\FileTypes\FileTypeGuard;
use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCP\Files\File;
use OCP\Files\Node;

/** Final-name evaluator shared by single files, tree preflight and each actual native sink. */
final class FileDestinationPolicy {
	/**
	 * @param FileTypeGuard $guard Global policy without a user or client bypass.
	 * @param CopyMoveContext $context Native visibility for bounded descendant checks.
	 */
	public function __construct(private FileTypeGuard $guard, private \OCA\Mcp\Modules\Files\Reorganization\CopyMoveContext $context) {
	}

	/** @return FileTypeCheck One immutable policy boundary shared by the entire preflight. */
	public function snapshot(): FileTypeCheck {
		return $this->guard->snapshot();
	}

	/**
	 * Check a resolved destination, optionally sharing a caller's immutable preflight snapshot.
	 *
	 * @param Node $source Resolved source, whose extension is deliberately ignored.
	 * @param string $to Normalized final destination path.
	 * @param FileTypeCheck|null $snapshot Shared preflight boundary for projected file names.
	 * @return void
	 * @throws \OCA\Mcp\Access\FileTypes\FileTypeDenied If the destination type is refused.
	 * @throws \OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable If policy cannot be evaluated.
	 */
	public function assertProjected(Node $source, string $to, ?FileTypeCheck $snapshot = null): void {
		if ($source instanceof File) {
			$segments = PathGuard::segments($to);
			($snapshot ?? $this->guard->snapshot())->assertWritableName($segments[array_key_last($segments)] ?? '');
		} else {
			(new \OCA\Mcp\Modules\Files\Reorganization\FileTypePreflight($this->context))->inspect($source, $to, $snapshot ?? $this->guard->snapshot());
		}
	}

	/**
	 * Re-read immediately inside the lock-aware callback, before the actual native sink.
	 *
	 * @param Node $source Resolved source whose identity the surrounding native flow preserves.
	 * @param string $to Final normalized path passed to the sink.
	 * @param callable():Node $write Official Nextcloud copy or move.
	 * @return Node Result of the official write.
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure If policy or the native write refuses.
	 */
	public function write(Node $source, string $to, callable $write): Node {
		$this->assertProjected($source, $to);
		return $write();
	}
}
