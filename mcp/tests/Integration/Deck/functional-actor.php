<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

use OCA\Mcp\Tests\Integration\Deck\FunctionalDeckNative;
use OCP\Server;

require dirname(__DIR__) . '/bootstrap.php';
[$script, $uid, $action] = $argv;
if (!str_starts_with($uid, 'mcp-it-dk-')) {
	throw new RuntimeException('Not an owned functional Deck actor');
}
if (Server::get(OCP\App\IAppManager::class)->isEnabledForAnyone('guests')) {
	OCA\Mcp\Tests\Integration\Guests\GuestDeckParityProbe::registerBackend(Server::get(OCP\App\IAppManager::class));
}
Server::get(OCP\IUserSession::class)->setUser(Server::get(OCP\IUserManager::class)->get($uid));
Server::get(OCP\ISession::class)->set('user_id', $uid);
OC_Util::setupFS($uid);
Server::get(OCP\App\IAppManager::class)->loadApp('deck');
Server::get(OCA\Deck\Service\BoardService::class)->setUserId($uid);
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
echo json_encode(FunctionalDeckNative::run($uid, $action, $input), JSON_THROW_ON_ERROR);
