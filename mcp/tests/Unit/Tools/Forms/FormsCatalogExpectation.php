<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Forms;

/** Registration-only updates to existing space-indented catalog fixtures. */
final class FormsCatalogExpectation {
	/** Adds the optional Forms module to a preexisting app availability fixture.
	 * @param array<string,bool> $expected Existing expectations.
	 * @return array<string,bool> Forms disabled like other absent optional apps.
	 */
	public static function apps(array $expected): array { return $expected + ['forms' => false]; }

	/** Adds the chosen Forms support floor without changing the other app policies.
	 * @param array<string,string> $expected Existing supported floors.
	 * @return array<string,string> Forms floor included.
	 */
	public static function versions(array $expected): array { return $expected + ['forms' => '5.3.0']; }
}
