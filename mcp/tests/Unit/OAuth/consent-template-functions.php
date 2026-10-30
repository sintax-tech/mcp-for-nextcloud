<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/*
 * The one Nextcloud template function consent.php uses, for rendering it outside the server. Declared only when the
 * real one is not loaded.
 */
if (!function_exists('p')) {
	/**
	 * Prints a value escaped for HTML, like Nextcloud's p().
	 *
	 * @param mixed $value text to print
	 * @return void
	 */
	function p($value): void {
		echo htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
	}
}
