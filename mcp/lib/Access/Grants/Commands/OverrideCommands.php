<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Grants\Commands;

use OCA\Mcp\Access\Grants\GrantPolicy;

/** Removes individual overrides while preserving raw absence. */
final class OverrideCommands implements OverrideCommandsContract {
	/**
	 * Composes this participant with the existing official services.
	 *
	 * @param CellWriter $cells Shared raw cell writes.
	 */
	public function __construct(
		private CellWriter $cells,
	) {}

	/** {@inheritDoc} */
	public function resetOverride(string $uid, string $module, string $operation): void {
		if (!GrantPolicy::inCatalog($module, $operation)) {
			throw new \InvalidArgumentException('Unknown module or operation');
		}
		$this->cells->change($uid, 'grant_' . $module . '_' . $operation, null);
	}
}
