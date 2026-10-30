<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Supplies the same localhost web request coordinates as Support/Http before native bootstrap URL resolution. */
final class PollsContentHttpEnvironment {
	/**
	 * Keeps the comparison in the same routing context as the actual HTTP endpoint; no URL is rewritten afterward.
	 * @return void
	 */
	public static function prepare(): void {
		$_SERVER['HTTP_HOST'] = 'localhost';
		$_SERVER['SERVER_NAME'] = 'localhost';
		$_SERVER['SERVER_PORT'] = '80';
		$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REQUEST_URI'] = '/index.php/apps/mcp/';
		$_SERVER['SCRIPT_NAME'] = '/index.php';
		$_SERVER['SCRIPT_FILENAME'] = getenv('NEXTCLOUD_ROOT') . '/index.php';
		unset($_SERVER['HTTPS']);
	}
}
