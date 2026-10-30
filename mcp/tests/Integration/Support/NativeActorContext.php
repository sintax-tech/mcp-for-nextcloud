<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Support;

use OC\Files\Filesystem;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;

/** Aligns the CLI fixture session and legacy relative filesystem view; production HTTP has one actor per request. */
final class NativeActorContext {
	/**
	 * @param string $uid Disposable actor whose native trash operation is being tested.
	 * @param callable():mixed $work Native operation, without changing its restoration behavior.
	 * @return mixed Native result; both prior session and view root are restored even on failure.
	 */
	public static function run(string $uid, callable $work): mixed {
		$session = Server::get(IUserSession::class);
		$before = $session->getUser();
		$view = Filesystem::getView();
		$previousRoot = $view?->getRoot();
		try {
			$session->setUser(Server::get(IUserManager::class)->get($uid));
			\OC_Util::setupFS($uid);
			$view = Filesystem::getView() ?? throw new \RuntimeException('Fixture filesystem was not initialized');
			$view->chroot('/' . $uid . '/files');
			return $work();
		} finally {
			if ($previousRoot === null) {
				FixtureFilesystem::tearDown();
			} else {
				$view?->chroot($previousRoot);
			}
			$session->setUser($before);
		}
	}
}
