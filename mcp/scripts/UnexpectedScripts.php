<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts;

require_once __DIR__ . '/AssetTargets.php';

/**
 * Refuses any JavaScript member of an app archive other than the two generated bundles.
 *
 * `package.sh` copies `js/` whole, so a script left there would ship next to `js/mcp-admin.js` and
 * `js/mcp-personal.js`. The allowed names come from {@see AssetTargets::MANIFESTS}, not from a second list.
 * Only `.js` files are refused: other file types under `mcp/js/` are not this class's concern.
 */
final class UnexpectedScripts {
	/**
	 * The problem for one archive member, if it is a script the package may not carry.
	 *
	 * @param string $name canonical member name (see {@see ArchiveMembers::canonical()}), such as `mcp/js/connections.js`
	 * @return string|null `unexpected script: <name>` or null when the member is allowed
	 */
	public static function problem(string $name): ?string {
		if (!str_starts_with($name, 'mcp/js/') || !str_ends_with(strtolower($name), '.js')) {
			return null;
		}
		foreach (AssetTargets::MANIFESTS as $bundle) {
			if ($name === 'mcp/' . $bundle) {
				return null;
			}
		}
		return "unexpected script: $name";
	}
}
