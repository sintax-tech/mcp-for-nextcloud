<?php
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
// Runs only inside the disposable SQLite instance while Nextcloud maintenance is on.
if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['backup', 'restore'], true)) exit(2);
require '/var/www/html/config/config.php';
if (($CONFIG['dbtype'] ?? '') !== 'sqlite3' || ($CONFIG['maintenance'] ?? false) !== true) {
    throw new RuntimeException('Recovery copying requires SQLite and maintenance mode');
}
$database = $CONFIG['datadirectory'] . '/' . $CONFIG['dbname'] . '.db';
$backup = '/tmp/mcp-it-recovery.sqlite';
[$from, $to] = $argv[1] === 'backup' ? [$database, $backup] : [$backup, $database];
if (!is_file($from)) throw new RuntimeException('Recovery database source missing');
$source = new SQLite3($from, SQLITE3_OPEN_READONLY);
$source->enableExceptions(true);
$target = new SQLite3($to, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
$target->enableExceptions(true);
if (!$source->backup($target)) throw new RuntimeException('SQLite recovery copy failed');
$target->close(); $source->close();
chmod($to, 0600);
echo "SQLite coordinated recovery copy completed: ", $argv[1], ".\n";
