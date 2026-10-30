<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Support;

use OCP\Server;

/** Resets only the disposable CLI fixture's filesystem between actor contexts. */
final class FixtureFilesystem {
	/**
	 * Uses the public setup interface when available and the native NC32 compatibility entry point otherwise.
	 *
	 * @return void
	 */
	public static function tearDown(): void {
		if (interface_exists(\OCP\Files\ISetupManager::class)) {
			Server::get(\OCP\Files\ISetupManager::class)->tearDown();
			return;
		}
		\OC_Util::tearDownFS();
	}
}
