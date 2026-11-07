<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/*
 * Upgrade cells only (harness.sh install-app): runs inside the container while the released baseline is still the
 * installed app, before the upgrade. It issues OAuth credentials through the baseline's own store and hasher, as a
 * client of that release would hold them: a pending code, a grant rotated once (which leaves a spent record) and a
 * second grant. The plain values and the rows as the baseline wrote them go to the file named by the argument, for
 * OAuth/LegacyCredentialsTest to check after the upgrade (validation T12). It also turns the native client on, as the
 * baseline's admin page does, for Compat/NativeClientMigrationTest. The owner is created later by the fixture
 * step; a row only names it.
 */

use OCA\Mcp\Tests\Integration\Compat\LegacyOAuthNames;
use OCP\IDBConnection;
use OCP\Server;

if (PHP_SAPI !== 'cli' || !isset($argv[1])) {
	fwrite(STDERR, "Usage: php legacy-credentials.php <output.json>\n");
	exit(1);
}
require_once '/var/www/html/lib/base.php';
require_once __DIR__ . '/../Compat/ReleaseUpgradeProbe.php';
\OCA\Mcp\Tests\Integration\Compat\ReleaseUpgradeProbe::checkpoint('credentials', $argv[1]);
Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');

require_once __DIR__ . '/../Compat/LegacyCredentialFixture.php';
\OCA\Mcp\Tests\Integration\Compat\LegacyCredentialFixture::write($argv[1], Server::get(\OCP\IAppConfig::class)->getValueString('mcp', 'installed_version'));
