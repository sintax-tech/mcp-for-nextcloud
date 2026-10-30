<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * Marks every folder of one Mail account of a Mail cell for background synchronization, through the Mail app's own
 * MailManager::enableMailboxBackgroundSync(), as the Mail app does once its user opens a folder. The account sync of the
 * Mail app caches the messages of the inbox, Sent and Trash only, and of the folders marked so; mail-cell.sh runs it
 * between two account syncs, so the message tools find every seeded folder cached.
 *
 * Usage (inside the Nextcloud container, as the web server user): php enable-background-sync.php <Mail account id>
 */

require '/var/www/html/lib/base.php';

[, $accountId] = $argv + [null, null];
if (!is_string($accountId) || !ctype_digit($accountId)) {
	fwrite(STDERR, "usage: php enable-background-sync.php <Mail account id>\n");
	exit(2);
}
\OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('mail');
$account = \OCP\Server::get('OCA\\Mail\\Service\\AccountService')->findById((int)$accountId);
$manager = \OCP\Server::get('OCA\\Mail\\Service\\MailManager');
$count = 0;
foreach (\OCP\Server::get('OCA\\Mail\\Db\\MailboxMapper')->findAll($account) as $mailbox) {
	$manager->enableMailboxBackgroundSync($mailbox, true);
	$count++;
}
echo "Background sync enabled for $count folders of account $accountId\n";
