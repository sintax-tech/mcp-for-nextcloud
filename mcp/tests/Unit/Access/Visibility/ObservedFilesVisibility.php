<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Access\Visibility;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCP\Files\Node;

/** Counts the actual Files guard's virtual methods while retaining their native policy behavior. */
final class ObservedFilesVisibility extends FilesAccessGuard {
	/** @var array<string, int> Calls reaching each virtual method. */
	public array $calls = ['visible' => 0, 'traversable' => 0, 'clear' => 0];

	/**
	 * @param Node $node Native node.
	 * @return bool The Files guard's actual visibility decision.
	 */
	public function isVisible(Node $node): bool {
		$this->calls['visible']++;
		return parent::isVisible($node);
	}

	/**
	 * @param Node $node Native path segment.
	 * @return bool The Files guard's actual traversal decision.
	 */
	public function isTraversable(Node $node): bool {
		$this->calls['traversable']++;
		return parent::isTraversable($node);
	}

	/** @return void Clear the actual Files guard's caches after counting the call. */
	public function clearCache(): void {
		$this->calls['clear']++;
		parent::clearCache();
	}
}
