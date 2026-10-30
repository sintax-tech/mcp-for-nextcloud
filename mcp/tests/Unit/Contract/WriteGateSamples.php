<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract;

/**
 * Sample values the write gate contract sends to every writing tool, for the schemas a generic rule cannot fill: a list
 * that needs items. With an item schema the sample is one sample item; without one (a list whose items are checked by the
 * tool, because the validator takes no union of shapes) it is the schema's own first `examples` entry, so a valid call
 * still goes through the gate; with neither, the empty list, which the schema then refuses.
 */
final class WriteGateSamples {
	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The sample of an array schema.
	 *
	 * @param array<string, mixed> $rule the array schema
	 * @param callable(array<string, mixed>): mixed $item the sample of an item schema
	 * @return list<mixed> the sample list
	 */
	public static function arrayValue(array $rule, callable $item): array {
		if (($rule['minItems'] ?? 0) <= 0) {
			return [];
		}
		if (isset($rule['items']) && is_array($rule['items'])) {
			return [$item($rule['items'])];
		}
		$example = $rule['examples'][0] ?? null;
		return is_array($example) && array_is_list($example) ? $example : [];
	}
}
