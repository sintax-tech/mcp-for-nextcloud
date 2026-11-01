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
	 * @return class-string baseline policy class resolved by the baseline loader
	 */
	public static function get(): string {
		return implode('\\', ['OCA', 'Mcp', 'Service', 'GrantPolicy']);
	}
}
