<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * One reader of MetadataDavTest, in its own CLI request: the session holds the user, as the MCP endpoint leaves it,
 * and FileMetadataDav reads the comments and the tags of each file ID of the input inside the user's policy operation.
 * For each ID it prints the status and body, or the refusal message; then the session user after all reads.
 */

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Files\Reading\FileMetadataDav;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;

require dirname(__DIR__) . '/bootstrap.php';
[, $uid] = $argv;
$in = json_decode((string)stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (!str_starts_with($uid, 'mcp-it-dav-')) {
	throw new RuntimeException('Not a user of the disposable metadata scene');
}
$session = Server::get(IUserSession::class);
$session->setUser(Server::get(IUserManager::class)->get($uid));
$dav = Server::get(FileMetadataDav::class);
$out = ['files' => []];
foreach ($in['files'] as $id) {
	$out['files'][(string)$id] = Server::get(GrantPolicy::class)->within($uid, static function () use ($dav, $uid, $id): array {
		$read = [];
		foreach (['comments' => static fn () => $dav->comments($uid, (int)$id, 20, 0), 'tags' => static fn () => $dav->tags($uid, (int)$id)] as $kind => $call) {
			try {
				$result = $call();
				$read[$kind] = ['status' => $result->status, 'body' => $result->body];
			} catch (ToolFailure $error) {
				$read[$kind] = ['refused' => $error->getMessage()];
			}
		}
		return $read;
	});
}
$out['session'] = $session->getUser()?->getUID();
echo json_encode($out, JSON_THROW_ON_ERROR);
