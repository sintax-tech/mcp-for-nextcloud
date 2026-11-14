<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\Preparation;

/** An address compared after a fragment search, never a display name. */
final class ExactEmail {
	/** Addresses only. */
	private function __construct() {
	}

	/**
	 * @param string $value a candidate address
	 * @return string|null the address in lower case, or null when it is not one address
	 */
	public static function normalize(string $value): ?string {
		$email = strtolower(trim($value));
		if ($email === '' || str_contains($email, ' ') || !str_contains($email, '@')) {
			return null;
		}
		return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
	}

	/**
	 * @param string $left one address
	 * @param string $right another address
	 * @return bool whether both are the same address
	 */
	public static function same(string $left, string $right): bool {
		$a = self::normalize($left);
		$b = self::normalize($right);
		return $a !== null && $a === $b;
	}
}
