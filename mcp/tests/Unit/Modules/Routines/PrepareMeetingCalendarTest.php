<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Routines;

use DateTimeImmutable;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCA\Mcp\Mcp\Tools\Discovery\ToolAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Modules\Calendar\CalendarMeetingEvents;
use OCA\Mcp\Modules\Calendar\Composing\DateInput;
use OCA\Mcp\Modules\Calendar\Integration\MeetingEventFacts;
use OCA\Mcp\Modules\Calendar\Integration\MeetingEventSupplement;
use OCA\Mcp\Modules\Calendar\Locating\Classification;
use OCA\Mcp\Modules\Calendar\Locating\EventExpander;
use OCA\Mcp\Modules\Calendar\Locating\EventRepository;
use OCA\Mcp\Modules\Calendar\Presentation\EventMapper;
use OCA\Mcp\Modules\Calendar\Reading\ListEventsHandler;
use OCA\Mcp\Modules\Routines\Preparation\AccountEmailMatch;
use OCA\Mcp\Modules\Routines\Preparation\CardCandidates;
use OCA\Mcp\Modules\Routines\Preparation\FileCandidates;
use OCA\Mcp\Modules\Routines\Preparation\MailCandidates;
use OCA\Mcp\Modules\Routines\Preparation\OccurrenceItems;
use OCA\Mcp\Modules\Routines\Preparation\ParticipantRoster;
use OCA\Mcp\Modules\Routines\Preparation\PrepareMeetingHandler;
use OCA\Mcp\Modules\Routines\Tools\PrepareMeeting;
use OCA\Mcp\Platform\Meetings\PreparationSources;
use OCA\Mcp\Tests\Unit\Tools\Calendar\FakeCalendarStore;
use OCA\Mcp\Tests\Unit\Tools\Calendar\ScopeStub;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The dossier over the real calendar reader and an ICS fixture.
 *
 * Mail, Deck and files answer through their ports. The public calendar tool is not this path.
 */
final class PrepareMeetingCalendarTest extends TestCase {
	private const ON = ['calendar.read', 'mail.messages', 'deck.read', 'files.read', 'people.read'];

	private FakeCalendarStore $store;
	private CalendarMeetingEvents $events;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new FakeCalendarStore();
		$this->store->addCalendar('principals/users/alice', 1, 'personal', 'principals/users/alice');
		$this->store->addCalendar('principals/users/alice', 3, 'team_shared_by_bob', 'principals/users/bob');
		$access = ScopeStub::access($this->store);
		$classification = new Classification();
		$repository = new EventRepository($this->store, $classification);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2026-04-09T00:00:00Z'));
		$listing = new ListEventsHandler($access, $this->store, $repository, $classification, new EventExpander(), new EventMapper(), new DateInput(), $time, new NullLogger());
		$facts = new MeetingEventSupplement($access, $this->store, $repository, $classification, new EventExpander(), new EventMapper(), new DateInput(), $time, new NullLogger(), new MeetingEventFacts());
		$this->events = new CalendarMeetingEvents($this->registry(), $listing, $facts);
	}

	/**
	 * Participants, a card link and a file link on the fixture come back through the ports. The dossier is not empty.
	 *
	 * @return void
	 */
	public function testAFixtureEventFillsMailDeckAndFilesThroughThePorts(): void {
		$this->store->addObject(1, 'meet.ics', $this->ics($this->body()));
		$ports = $this->ports();
		$dossier = $this->dossier($ports, self::ON);
		$item = OccurrenceItems::fromResult($this->events->events('alice', self::window()))[0];
		self::assertSame('ada@example.com', $item['attendees'][0]['email']);
		self::assertSame('Ada', $item['attendees'][0]['name']);
		self::assertSame('pedro@example.com', $item['attendees'][1]['email']);
		self::assertNotContains('AAAA', $item['links']);
		self::assertFalse($dossier['written']);
		self::assertSame('Planning', $dossier['event']['summary']);
		self::assertSame(['ada@example.com', 'pedro@example.com'], array_column($dossier['participants'], 'email'));
		self::assertSame([null, 'pedro'], array_column($dossier['participants'], 'uid'));
		self::assertNotEmpty($dossier['sources']['mail']['candidates']);
		self::assertSame(['8', '12'], array_map('strval', array_column($dossier['sources']['deck']['candidates'], 'cardId')));
		self::assertNotContains(99, array_column($dossier['sources']['deck']['candidates'], 'cardId'));
		self::assertNotEmpty($dossier['sources']['files']['candidates']);
		$encoded = json_encode($dossier, JSON_THROW_ON_ERROR);
		self::assertStringNotContainsString('board refused', $encoded);
		self::assertStringNotContainsString('"omitted"', $encoded);
		self::assertStringNotContainsString('"failed"', $encoded);
		foreach ($ports->calls as [$name, $arguments]) {
			self::assertArrayNotHasKey('body', $arguments);
		}
	}

	/**
	 * A confidential event of another owner stays busy: no participants and no links, so the other sources stay empty.
	 *
	 * @return void
	 */
	public function testAConfidentialEventOfAnotherOwnerHasNoParticipantsAndNoLinks(): void {
		$this->store->addObject(3, 'busy.ics', $this->ics("CLASS:CONFIDENTIAL\n" . $this->body('secret')));
		$items = OccurrenceItems::fromResult($this->events->events('alice', self::window()));
		self::assertSame('Ocupado', $items[0]['summary']);
		self::assertArrayNotHasKey('attendees', $items[0]);
		self::assertArrayNotHasKey('links', $items[0]);
		$dossier = $this->dossier($this->ports(), self::ON);
		self::assertSame('Ocupado', $dossier['event']['summary']);
		self::assertSame([], $dossier['participants']);
		self::assertSame('no_address', $dossier['sources']['mail']['reason']);
		self::assertSame('no_card', $dossier['sources']['deck']['reason']);
		self::assertSame('no_path', $dossier['sources']['files']['reason']);
		self::assertSame([], $dossier['sources']['deck']['candidates']);
		self::assertSame([], $dossier['sources']['files']['candidates']);
	}

	/**
	 * @param list<string> $grants module.operation pairs that are granted
	 * @return array<string, mixed> the decoded dossier
	 */
	private function dossier(PreparationDouble $ports, array $grants): array {
		$policy = $this->createMock(GrantPolicy::class);
		$policy->method('granted')->willReturnCallback(
			static fn (string $uid, string $module, string $operation): bool => in_array($module . '.' . $operation, $grants, true),
		);
		$search = $this->createMock(ISearch::class);
		$search->method('search')->willReturnCallback(static function (string $email): array {
			if ($email !== 'pedro@example.com') {
				return [[], false];
			}
			return [['exact' => ['users' => [['value' => ['shareWith' => 'pedro'], 'subline' => 'pedro@example.com']]]], false];
		});
		$sources = new PreparationSources($this->events, $ports, $ports, $ports);
		$tool = new PrepareMeeting(new PrepareMeetingHandler(
			$sources,
			$policy,
			new ParticipantRoster($policy, new AccountEmailMatch($search)),
			new MailCandidates($sources, $policy),
			new CardCandidates($sources, $policy),
			new FileCandidates($sources, $policy),
		));
		$result = $tool->execute(self::window(), new ToolContext('alice'));
		$decoded = json_decode($result['content'][0]['text'], true);
		self::assertIsArray($decoded);
		return $decoded;
	}

	/**
	 * @return PreparationDouble mail, Deck and files, including one card the actor cannot open
	 */
	private function ports(): PreparationDouble {
		return new PreparationDouble(
			['content' => []],
			static fn (string $userId, array $arguments): array => PreparationDouble::json([
				'messages' => [[
					'id' => 7, 'subject' => 'Agenda', 'date' => '2026-04-01', 'url' => 'https://cloud.example/mail/7', 'mailboxId' => 1,
					'from' => ['count' => 1, 'addresses' => [['name' => 'Ada', 'email' => 'ada@example.com']]],
				]],
				'folders' => [['mailboxId' => 1]],
			]),
			static function (string $userId, array $arguments): array {
				if (($arguments['cardId'] ?? 0) === 99) {
					throw new ToolFailure('board refused');
				}
				$id = (int)$arguments['cardId'];
				return PreparationDouble::json(['title' => 'Card ' . $id, 'url' => 'https://cloud.example/apps/deck/board/4/card/' . $id]);
			},
			static fn (string $userId, array $arguments): array => PreparationDouble::json(['items' => [['path' => '/Notes/from-card.md']]]),
			static fn (string $userId, array $arguments): array => ['content' => [
				['type' => 'text', 'text' => 'notes'],
				['type' => 'text', 'text' => json_encode(['path' => $arguments['path'], 'etag' => 'e1'], JSON_THROW_ON_ERROR)],
			]],
			static fn (string $userId, string $participantUid): array => $participantUid === 'pedro' ? [12] : [],
			static function (string $userId, string $reference): ?string {
				if (str_contains($reference, '/f/15')) {
					return '/Notes/from-event.md';
				}
				if (str_contains($reference, '/remote.php/dav/files/')) {
					return '/Notes/slide.md';
				}
				return null;
			},
		);
	}

	/**
	 * @return ToolAccess a registry that admits calendar_list_events
	 */
	private function registry(): ToolAccess {
		$policy = $this->createMock(GrantPolicy::class);
		$policy->method('grantedAny')->willReturn(true);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturn(true);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$guests = $this->createMock(GuestRestrictions::class);
		$guests->method('allowsApp')->willReturn(true);
		return new ToolAccess($policy, $apps, $users, $guests, new CapabilityRegistry([], new NullLogger()));
	}

	/**
	 * @param string $uid the event UID
	 * @return string the VEVENT body, with participants and links
	 */
	private function body(string $uid = 'meet'): string {
		return "UID:$uid\nSUMMARY:Planning\nDTSTART:20260409T100000Z\nDTEND:20260409T110000Z\n"
			. "ORGANIZER;CN=Ada:mailto:ada@example.com\nATTENDEE;CN=Pedro:mailto:pedro@example.com\n"
			. "URL:https://cloud.example/apps/deck/board/4/card/8\n"
			. "DESCRIPTION:Notes https://cloud.example/f/15 and https://cloud.example/apps/deck/board/9/card/99\n"
			. "LOCATION:https://cloud.example/remote.php/dav/files/alice/Notes/slide.md\n"
			. "ATTACH;ENCODING=BASE64;VALUE=BINARY:AAAA\n";
	}

	/**
	 * @param string $body VEVENT lines
	 * @return string an iCalendar object
	 */
	private function ics(string $body): string {
		return str_replace("\n", "\r\n", "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//test//EN\nBEGIN:VEVENT\n" . $body . "END:VEVENT\nEND:VCALENDAR\n");
	}

	/**
	 * @return array{from: string, to: string} the window that contains 9 April 2026
	 */
	private static function window(): array {
		return ['from' => '2026-04-08T00:00:00Z', 'to' => '2026-04-10T00:00:00Z'];
	}
}
