<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Compat;

/** Historical OAuth identities resolved exclusively by the installed release's loader. */
final class LegacyOAuthNames {
	/** Prevents instantiation of historical class names. */
	private function __construct() {
	}

	/** @param string $version Installed baseline version; historical callers default to 1.0.8.
	 * @return class-string Store declared by the released baseline. */
	public static function store(string $version = '1.0.8'): string {
		return implode('\\', version_compare($version, '1.3.0-rc.1', '>=')
			? ['OCA', 'Mcp', 'OAuth', 'Storage', 'OAuthStore'] : ['OCA', 'Mcp', 'OAuth', 'OAuthStore']);
	}

	/** @param string $version Installed baseline version; historical callers default to 1.0.8.
	 * @return class-string Hasher declared by the released baseline. */
	public static function hasher(string $version = '1.0.8'): string {
		return implode('\\', version_compare($version, '1.3.0-rc.1', '>=')
			? ['OCA', 'Mcp', 'OAuth', 'Storage', 'TokenHasher'] : ['OCA', 'Mcp', 'OAuth', 'TokenHasher']);
	}

	/** @param string $version Installed baseline. @return class-string Public client admission command service. */
	public static function clients(string $version = '1.0.8'): string {
		return implode('\\', version_compare($version, '1.3.0-rc.1', '>=')
			? ['OCA', 'Mcp', 'OAuth', 'Clients', 'OAuthClientsService'] : ['OCA', 'Mcp', 'OAuth', 'OAuthClientsService']);
	}
}
