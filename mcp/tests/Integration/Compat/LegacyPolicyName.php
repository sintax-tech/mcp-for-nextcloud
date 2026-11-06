<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Compat;

/** Names the released baseline's policy, independently of the installed branch's namespace. */
final class LegacyPolicyName {
	/** Prevents instantiation of this historical identity. */
	private function __construct() {
	}

	/**
	 * The v1.0.8 snapshot always executes the baseline's own declaration and constructor.
	 * Segments keep this historical identity distinct from references to the current app.
	 *
	 * @param string $version Installed baseline version; historical callers default to 1.0.8.
	 * @return class-string baseline policy class resolved by the baseline loader
	 */
	public static function get(string $version = '1.0.8'): string {
		return implode('\\', version_compare($version, '1.3.0-rc.1', '>=')
			? ['OCA', 'Mcp', 'Access', 'Grants', 'GrantPolicy'] : ['OCA', 'Mcp', 'Service', 'GrantPolicy']);
	}
	/** @param string $version Installed baseline version. @return class-string Its public policy command service. */
	public static function writer(string $version): string {
		return implode('\\', version_compare($version, '1.3.0-rc.1', '>=')
			? ['OCA', 'Mcp', 'Access', 'Policy', 'PolicyWriter'] : ['OCA', 'Mcp', 'Service', 'Policy', 'PolicyWriter']);
	}
}
