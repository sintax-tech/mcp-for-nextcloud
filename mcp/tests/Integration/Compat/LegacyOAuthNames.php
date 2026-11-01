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

	/** @return class-string Store declared by the released baseline. */
	public static function store(): string {
		return implode('\\', ['OCA', 'Mcp', 'OAuth', 'OAuthStore']);
	}

	/** @return class-string Hasher declared by the released baseline. */
	public static function hasher(): string {
		return implode('\\', ['OCA', 'Mcp', 'OAuth', 'TokenHasher']);
	}
}
