<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Autoloader of the development scripts (`OCA\Mcp\Scripts\Dev\*` in scripts/dev/src). These scripts never ship:
 * scripts/package.sh copies only the production folders.
 */
spl_autoload_register(static function (string $class): void {
	$prefix = 'OCA\\Mcp\\Scripts\\Dev\\';
	if (str_starts_with($class, $prefix)) {
		$file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) {
			require $file;
		}
	}
});
