<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service;

use OCP\Files\Node;

/** Evaluates original-file exclusions for mirrors in a verified backup folder. */
final class BackupMirrorVisibility {
	/**
	 * @param VisibilityGuard $guard shared exclusion evaluator and mount-aware parent resolver
	 * @param BackupFolderMatcher $matcher backup location classifier
	 * @param \Closure(): void $unavailable privacy-safe failure reporter
	 */
	public function __construct(private VisibilityGuard $guard, private BackupFolderMatcher $matcher, private \Closure $unavailable) {
	}

	/**
	 * Preserves the inspector's outer reason while evaluating the original, carrying only an unknown refusal out.
	 *
	 * @param Node $node possible mirror
	 * @param array<string, mixed>|null $explanation current inspector state, updated only for an unresolved original
	 * @return bool whether any existing original is excluded or cannot be evaluated
	 */
	public function isHidden(Node $node, ?array &$explanation): bool {
		try {
			foreach ($this->matcher->matches($node, $this->guard->parentOf(...)) as [$home, $segments]) {
				$leaf = array_pop($segments);
				if (preg_match('/^(.+)\.\d{8}-\d{6}(?:-\d+)?\.bak$/', $leaf, $matches)) {
					$leaf = $matches[1];
				}
				$path = implode('/', [...$segments, $leaf]);
				if ($path === '' || !$home->nodeExists($path)) {
					continue;
				}
				$originalNode = $home->get($path);
				[$outer, $explanation] = [$explanation, $explanation === null ? null : []];
				try {
					$hidden = !$this->guard->isVisible($originalNode);
					$original = $explanation;
				} finally {
					$explanation = $outer;
				}
				if ($hidden) {
					if ($original !== null && ($original['reason'] ?? VisibilityGuard::EXPLAINED_NOT_EVALUATED) === VisibilityGuard::EXPLAINED_NOT_EVALUATED) {
						($this->unavailable)();
					}
					return true;
				}
			}
			return false;
		} catch (\Throwable) {
			($this->unavailable)();
			return true;
		}
	}
}
