<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\FileAccess;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Node;

/** Visibility contract consumed by shared file operations without depending on access policy. */
interface WriteVisibility {
	/** @return void Discard cached visibility after a mutation, including a partially failed mutation. */
	public function clearCache(): void;

	/**
	 * @param Node $node Resource requested by the caller.
	 * @return void Return only when the resource may be named and reached.
	 * @throws ToolFailure With CommonMessages::notFound() when the resource is hidden.
	 */
	public function assertVisible(Node $node): void;

	/**
	 * @param Node $node Existing path segment crossed without reading its content.
	 * @return void Return only when the path may be traversed.
	 * @throws ToolFailure With CommonMessages::notFound() when traversal is hidden.
	 */
	public function assertTraversable(Node $node): void;

	/**
	 * @param Node $node Resource whose name might enter a lock explanation.
	 * @return bool Whether the resource may be named in the response.
	 */
	public function isVisible(Node $node): bool;
}
