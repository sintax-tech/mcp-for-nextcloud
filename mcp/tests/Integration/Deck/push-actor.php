<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use OCP\Server;

// Issue #26: Deck's own assignUser() for a bound caller, with no MCP code and no deferral, as the 1.2.5 gateway
// called it. Prints the stored state and, when Deck throws, the exception class, origin and the top of its trace.
require dirname(__DIR__) . '/bootstrap.php';
[$script, $uid] = $argv;
if (!str_starts_with($uid, 'mcp-it-dk-')) {
	throw new RuntimeException('Not an owned functional Deck actor');
}
Server::get(OCP\IUserSession::class)->setUser(Server::get(OCP\IUserManager::class)->get($uid));
Server::get(OCP\ISession::class)->set('user_id', $uid);
OC_Util::setupFS($uid);
OC_App::loadApps();
Server::get(OCA\Deck\Service\BoardService::class)->setUserId($uid);
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$out = ['error' => null];
try {
	Server::get(OCA\Deck\Service\AssignmentService::class)->assignUser((int)$input['card'], (string)$input['assignee']);
} catch (Throwable $e) {
	$chain = [];
	for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
		$chain[] = ['class' => $cause::class, 'message' => substr($cause->getMessage(), 0, 300),
			'at' => basename($cause->getFile()) . ':' . $cause->getLine()];
	}
	$frames = array_map(static fn (array $f): string => ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '')
		. ' @' . basename($f['file'] ?? '?') . ':' . ($f['line'] ?? '?'), array_slice($e->getTrace(), 0, 14));
	$out['error'] = ['chain' => $chain, 'trace' => $frames];
}
$out['assignees'] = array_map(static fn ($a): string => (string)$a->getParticipant(),
	Server::get(OCA\Deck\Db\AssignmentMapper::class)->findAll((int)$input['card']));
echo json_encode($out, JSON_THROW_ON_ERROR);
