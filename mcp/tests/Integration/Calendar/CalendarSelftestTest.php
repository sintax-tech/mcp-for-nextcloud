<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Calendar;

use OCA\Mcp\Command\CalendarSelftest;
use OCA\Mcp\Platform\Compat\AppEnablement;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Server;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The optional `occ mcp:calendar-selftest` on the real server of the cell: the command the container builds runs the
 * real drafts through the real embedded CalDAV pipeline for a throwaway account, and every step and its cleanup pass.
 * It proves the Calendar bindings that moved to CalendarRegistration (the CalDAV store and write port, the dispatcher
 * and the selftest reader) against the server, not only against doubles.
 */
final class CalendarSelftestTest extends IntegrationTestCase {
	/** Throwaway account the selftest runs for. */
	private const UID = 'mcp-it-cal-selftest';

	private ?IUser $user = null;

	protected function tearDown(): void {
		$this->user?->delete();
		$this->user = null;
		parent::tearDown();
	}

	public function testTheSelftestPassesForAFreshAccount(): void {
		if (!AppEnablement::forAnyone(Server::get(IAppManager::class), 'calendar')) {
			$this->markTestSkipped('The Calendar app is not enabled in this cell; see provision.log in its artifact');
		}
		$users = Server::get(IUserManager::class);
		$users->get(self::UID)?->delete();
		$this->user = $users->createUser(self::UID, bin2hex(random_bytes(24))) ?: null;
		$this->assertNotNull($this->user, 'the throwaway account was created');
		$this->user->setSystemEMailAddress(self::UID . '@example.invalid');

		$tester = new CommandTester(Server::get(CalendarSelftest::class));
		$code = $tester->execute(['uid' => self::UID]);

		$this->assertSame(0, $code, $tester->getDisplay());
	}
}
