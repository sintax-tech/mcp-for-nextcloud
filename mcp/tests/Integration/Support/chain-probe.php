<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

// Run by the diagnostics test in a fresh PHP process, like a web request sees the tree for the first time:
// php chain-probe.php <uid> <path inside the user's folder>. Prints the ancestors of the node up to the root, as the
// visibility guard walks them, and the owner-tree lookup it makes for a share.
define('OC_CONSOLE', 1);
require_once '/var/www/html/lib/base.php';

use OCP\Files\IRootFolder;
use OCP\Files\Storage\ISharedStorage;
use OCP\Server;

[, $uid, $path] = $argv;
$rootFolder = Server::get(IRootFolder::class);
$userFolder = $rootFolder->getUserFolder($uid);
echo 'user folder: ', $userFolder::class, ' id=', $userFolder->getId(), "\n";
try {
	$node = $userFolder->get($path);
} catch (Throwable $e) {
	echo 'get failed: ', $e::class, ': ', $e->getMessage(), "\n";
	exit(0);
}
$current = $node;
for ($i = 0; $i < 12; $i++) {
	try {
		$storage = $current->getStorage();
		$owner = $current->getOwner();
		echo sprintf("%s id=%d path=%s storage=%s shared=%s mount=%s owner=%s\n", $current::class, (int)$current->getId(), $current->getPath(),
			$storage->getId(), $storage->instanceOfStorage(ISharedStorage::class) ? 'yes' : 'no', $current->getMountPoint()->getMountPoint(),
			$owner === null ? 'null' : $owner->getUID());
	} catch (Throwable $e) {
		echo $current::class, ' step failed: ', $e::class, ': ', $e->getMessage(), "\n";
	}
	if ($current instanceof IRootFolder) {
		break;
	}
	try {
		$current = $current->getParent();
	} catch (Throwable $e) {
		echo 'getParent failed: ', $e::class, ': ', $e->getMessage(), "\n";
		break;
	}
}
