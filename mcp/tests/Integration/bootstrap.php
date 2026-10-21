<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

// Boots the real, installed Nextcloud of the CI container; there are no stubs here. The unit suite never loads this.
$root = getenv('NEXTCLOUD_ROOT');
if (!is_string($root) || $root === '' || !is_file($root . '/lib/base.php')) {
    fwrite(STDERR, "NEXTCLOUD_ROOT must point to an installed Nextcloud; the integration suite runs only in CI.\n");
    exit(1);
}
require_once $root . '/lib/base.php';

if (!\OCP\Server::get(\OCP\IConfig::class)->getSystemValueBool('installed')) {
    fwrite(STDERR, "The Nextcloud at NEXTCLOUD_ROOT is not installed.\n");
    exit(1);
}
// Registers the installed app's autoloader and runs its bootstrap, as a request would.
\OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('mcp');

spl_autoload_register(static function (string $class): void {
    $prefix = 'OCA\\Mcp\\Tests\\Integration\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require_once __DIR__ . '/../Support/Access/Policy/PolicyServices.php';
