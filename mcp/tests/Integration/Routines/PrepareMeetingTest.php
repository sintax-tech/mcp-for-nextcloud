<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Routines;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Platform\Compat\AppEnablement;
use OCA\Mcp\Tests\Integration\Fixtures;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Support\LoginFactory;
use OCA\Mcp\Tests\Integration\Support\McpClient;
use OCP\App\IAppManager;
use OCP\Server;

/**
 * routines_prepare_meeting over the real Calendar of this cell, and no other source.
 *
 * Mail, Deck and files stay not consulted. The object this test writes is removed in tearDown.
 */
final class PrepareMeetingTest extends IntegrationTestCase {
	/** The event this test stores in Alice's personal calendar. */
	private const UID = 'mcp-it-prepare-meeting';

	/** @var array<string, bool> grants of Alice before the test, by module.operation */
	private array $before = [];

	/** @var list<array{string, string, bool}> grants this test sets, restored in tearDown */
	private const GRANTS = [
		['routines', 'prepare', true],
		['calendar', 'read', true],
		['mail', 'messages', false],
		['deck', 'read', false],
		['files', 'read', false],
		['people', 'read', false],
	];

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$apps = Server::get(IAppManager::class);
		if (!AppEnablement::forAnyone($apps, 'calendar')) {
			$this->markTestSkipped('Calendar is not enabled in this cell; see provision.log in its artifact');
		}
		$policy = Server::get(GrantPolicy::class);
		$policy->setGlobalEnabled(true);
		$policy->setEligible(Fixtures::ALICE, true);
		$policy->setConnected(Fixtures::ALICE, true);
		foreach (self::GRANTS as [$module, $operation, $enabled]) {
			$this->before[$module . '.' . $operation] = $policy->granted(Fixtures::ALICE, $module, $operation);
			$policy->setGrant(Fixtures::ALICE, $module, $operation, $enabled);
		}
		$calendar = $this->personal();
		if ($calendar === null) {
			$this->markTestSkipped('Alice has no personal calendar in this cell');
		}
		$this->backend()->createCalendarObject((int)$calendar['id'], self::UID . '.ics', $this->ics());
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		$policy = Server::get(GrantPolicy::class);
		foreach ($this->before as $grant => $enabled) {
			[$module, $operation] = explode('.', $grant, 2);
			$policy->setGrant(Fixtures::ALICE, $module, $operation, $enabled);
		}
		$calendar = $this->personal();
		if ($calendar !== null) {
			$this->backend()->deleteCalendarObject((int)$calendar['id'], self::UID . '.ics', CalDavBackend::CALENDAR_TYPE_CALENDAR, true);
		}
		parent::tearDown();
	}

	/**
	 * The real calendar reader returns the participant. Mail, Deck and files are not consulted.
	 *
	 * @return void
	 */
	public function testOnlyCalendarIsConsultedAndTheParticipantIsPresent(): void {
		$alice = new McpClient(LoginFactory::basic(Fixtures::ALICE));
		$reply = $alice->tool('routines_prepare_meeting', [
			'from' => '2026-11-01T00:00:00Z',
			'to' => '2026-11-20T00:00:00Z',
			'calendar' => '/remote.php/dav/calendars/' . Fixtures::ALICE . '/personal/',
			'uid' => self::UID,
		]);
		$this->assertTrue($reply->ok(), $reply->describe());
		$dossier = $reply->json();
		$this->assertIsArray($dossier);
		$this->assertFalse($dossier['written']);
		$this->assertSame(['ada@example.com'], array_column($dossier['participants'], 'email'));
		$this->assertNull($dossier['participants'][0]['uid']);
		foreach (['mail', 'deck', 'files'] as $source) {
			$this->assertSame('not_consulted', $dossier['sources'][$source]['status']);
			$this->assertSame('grant', $dossier['sources'][$source]['reason']);
			$this->assertSame([], $dossier['sources'][$source]['candidates']);
		}
	}

	/**
	 * @return array<string, mixed>|null Alice's personal calendar, when the cell has one
	 */
	private function personal(): ?array {
		$calendar = $this->backend()->getCalendarByUri('principals/users/' . Fixtures::ALICE, 'personal');
		return is_array($calendar) ? $calendar : null;
	}

	/**
	 * @return CalDavBackend the cell's calendar storage
	 */
	private function backend(): CalDavBackend {
		return Server::get(CalDavBackend::class);
	}

	/**
	 * @return string one public event with an organizer
	 */
	private function ics(): string {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//mcp-it//EN\r\nBEGIN:VEVENT\r\nUID:" . self::UID
			. "\r\nDTSTAMP:20261113T100000Z\r\nDTSTART:20261113T100000Z\r\nDTEND:20261113T110000Z\r\nSUMMARY:Prepare\r\n"
			. "ORGANIZER;CN=Ada:mailto:ada@example.com\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
	}
}
