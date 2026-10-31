<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCP\IDBConnection;
use OCP\Server;

// A separate PHP process is essential: no inherited PDO handles, and both queries can block concurrently.
$db = Server::get(IDBConnection::class);
$gid = $argv[1];
$advanceRevision = $argv[2] === '1';
$state = new PolicyStateMapper($db);
$groups = new GroupGrantsMapper($db);
try {
	$db->beginTransaction();
	// Deliberately invert the production order, which is mutex -> local rows. Normal policy writers cannot
	// deadlock each other this way. These are app-owned rows and the same mapper statements used by the app.
	$groups->replaceGroup($gid, ['notes' => ['edit']]);
	// InnoDB picks the smaller transaction as victim. Give this survivor more undo/row locks than the writer.
	for ($i = 0; $i < 64; ++$i) {
		$groups->replaceGroup($gid . '-weight-' . $i, ['notes' => ['edit']]);
	}
	fwrite(STDOUT, "locked\n");
	fflush(STDOUT);
	if (trim((string)fgets(STDIN)) !== 'cycle') {
		throw new RuntimeException('Missing cycle barrier');
	}
	// Observe the parent's real lock wait before closing the cycle. This avoids selecting PostgreSQL's
	// survivor as victim merely because the parent was descheduled between the pipe write and its DELETE.
	if ($db->getDatabaseProvider() === IDBConnection::PLATFORM_POSTGRES) {
		$deadline = microtime(true) + 10;
		do {
			$db->executeQuery('SELECT pg_stat_clear_snapshot()')->closeCursor();
			$result = $db->executeQuery('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [(int)$argv[3]]);
			$waiting = $result->fetchOne() === 'Lock';
			$result->closeCursor();
			if (!$waiting) {
				usleep(10000);
			}
		} while (!$waiting && microtime(true) < $deadline);
		if (!$waiting) {
			throw new RuntimeException('Parent did not reach the group-row lock barrier');
		}
	}
	// Extra scheduling margin only: PostgreSQL's barrier already establishes the order, and InnoDB's
	// transaction weight selects the victim. Correctness does not depend on this delay.
	usleep(100000);
	$state->acquire();
	if ($advanceRevision) {
		$state->compareAndSet($state->state()['revision']);
	}
	for ($i = 0; $i < 64; ++$i) {
		$groups->deleteGroup($gid . '-weight-' . $i);
	}
	$db->commit();
	fwrite(STDOUT, "committed\n");
	fflush(STDOUT);
} catch (Throwable $e) {
	fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");
	if ($db->inTransaction()) {
		$db->rollBack();
	}
	exit(1);
}
