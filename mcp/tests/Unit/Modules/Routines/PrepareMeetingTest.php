<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Routines;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolPresentation;
use OCA\Mcp\Modules\Routines\Preparation\AccountEmailMatch;
use OCA\Mcp\Modules\Routines\Preparation\CardCandidates;
use OCA\Mcp\Modules\Routines\Preparation\FileCandidates;
use OCA\Mcp\Modules\Routines\Preparation\MailCandidates;
use OCA\Mcp\Modules\Routines\Preparation\ParticipantRoster;
use OCA\Mcp\Modules\Routines\Preparation\PrepareMeetingHandler;
use OCA\Mcp\Modules\Routines\Presentation\RoutinesMessages;
use OCA\Mcp\Modules\Routines\RoutinesToolCatalog;
use OCA\Mcp\Modules\Routines\Tools\PrepareMeeting;
use OCA\Mcp\Platform\Meetings\PreparationSources;
use OCA\Mcp\Platform\Workflows\WorkflowMessages;
use OCP\Collaboration\Collaborators\ISearch;
use PHPUnit\Framework\TestCase;

/**
 * routines_prepare_meeting over port doubles: the event the calendar reader already returned, and nothing written.
 */
final class PrepareMeetingTest extends TestCase {
	use BuildsCloudLink;
	/** @var list<string> grants of a dossier that may consult every source */
	private const ON = ['calendar.read', 'mail.messages', 'deck.read', 'files.read', 'people.read'];

	/**
	 * Recurrence stays in the reader. The next event is the earliest occurrence that reader returned inside the window.
	 *
	 * @return void
	 */
	public function testNextEventIsTheEarliestOccurrenceTheReaderReturned(): void {
		$early = self::occurrence('series', '2026-04-02T10:00:00Z', '2026-04-02T11:00:00Z');
		$next = self::occurrence('series', '2026-04-09T10:00:00Z', '2026-04-09T11:00:00Z');
		$later = self::occurrence('series', '2026-04-09T15:00:00Z', '2026-04-09T16:00:00Z');
		$ports = new PreparationDouble(PreparationDouble::json([$later, $early, $next]));
		$dossier = $this->dossier($this->tool($ports, self::ON), self::window());
		self::assertSame('next', $dossier['selection']);
		self::assertSame('2026-04-09T10:00:00Z', $dossier['event']['start']);
		self::assertArrayNotHasKey('uid', $ports->calls[0][1]);
	}

	/**
	 * A private or confidential event is refused when the calendar reader does not return it. This dossier does not invent a CLASS.
	 *
	 * @return void
	 */
	public function testAnEventTheReaderOmitsIsRefused(): void {
		$ports = new PreparationDouble(PreparationDouble::json([self::occurrence('other')]));
		$tool = $this->tool($ports, self::ON);
		try {
			$tool->execute(self::window(['calendar' => '/calendars/alice/personal/', 'uid' => 'hidden']), new ToolContext('alice'));
			self::fail('A missing event must be refused');
		} catch (ToolFailure $error) {
			self::assertSame(RoutinesMessages::eventNotVisible(), $error->getMessage());
		}
		self::assertSame(['events'], array_column($ports->calls, 0));
	}

	/**
	 * An occurrence the reader returned is kept, including a busy summary. CLASS is not read again, and the summary is not scraped.
	 *
	 * @return void
	 */
	public function testAShownOccurrenceIsNotFilteredByClass(): void {
		$item = ['class' => 'CONFIDENTIAL', 'summary' => 'Ocupado', 'location' => 'Room ada@example.com'] + self::occurrence();
		$ports = new PreparationDouble(PreparationDouble::json([$item]));
		$dossier = $this->dossier($this->tool($ports, self::ON), self::window(['calendar' => '/calendars/alice/personal/', 'uid' => 'evt-1']));
		self::assertSame('explicit', $dossier['selection']);
		self::assertSame('Ocupado', $dossier['event']['summary']);
		self::assertArrayNotHasKey('class', $dossier['event']);
		self::assertSame('no_address', $dossier['sources']['mail']['reason']);
		self::assertSame(['events'], array_column($ports->calls, 0));
	}

	/**
	 * prepare is denied by default. The tool stays a read, and a denied calendar.read never reaches the reader.
	 *
	 * @return void
	 */
	public function testPrepareIsDeniedByDefault(): void {
		$definition = PrepareMeeting::definition();
		self::assertFalse(GrantPolicy::defaultGranted('routines', 'prepare'));
		self::assertSame('read', $definition->operation);
		self::assertSame(['prepare'], $definition->grantAnyOf);
		self::assertSame('calendar', $definition->app);
		self::assertArrayNotHasKey('confirm', $definition->inputSchema['properties']);
		self::assertTrue(ToolPresentation::annotations($definition->name, $definition->operation, $definition->destructiveHint)['readOnlyHint']);
		self::assertSame(PrepareMeeting::class, RoutinesToolCatalog::TOOLS[array_key_last(RoutinesToolCatalog::TOOLS)]);
		$ports = new PreparationDouble(PreparationDouble::json([self::occurrence()]));
		try {
			$this->tool($ports, [])->execute(self::window(), new ToolContext('alice'));
			self::fail('calendar.read must be required');
		} catch (ToolFailure $error) {
			self::assertSame(WorkflowMessages::notAllowed('calendar_list_events'), $error->getMessage());
		}
		self::assertSame([], $ports->calls);
	}

	/**
	 * The dossier calls only the read ports and says that nothing was written.
	 *
	 * @return void
	 */
	public function testTheDossierWritesNothing(): void {
		$item = self::occurrence() + ['attendees' => ['ada@example.com'], 'links' => ['/apps/deck/board/1/card/4', '/Notes/a.md']];
		$ports = new PreparationDouble(
			PreparationDouble::json([$item]),
			static fn (string $userId, array $arguments): array => PreparationDouble::json(['messages' => []]),
			static fn (string $userId, array $arguments): array => PreparationDouble::json(['id' => 4, 'title' => 'Brief', 'lastModified' => 1710000000, 'url' => 'https://cloud.example/apps/deck/board/1/card/4']),
			static fn (string $userId, array $arguments): array => PreparationDouble::json(['items' => []]),
			static fn (string $userId, array $arguments): array => ['content' => [
				['type' => 'text', 'text' => 'Agenda'],
				['type' => 'text', 'text' => json_encode(['path' => '/Notes/a.md', 'etag' => 'abc', 'size' => 6], JSON_THROW_ON_ERROR)],
			]],
			null,
			static fn (string $userId, string $reference): ?string => $reference === '/Notes/a.md' ? $reference : null,
		);
		$dossier = $this->dossier($this->tool($ports, self::ON), self::window());
		self::assertFalse($dossier['written']);
		self::assertTrue($dossier['untrustedDossier']);
		self::assertSame(RoutinesMessages::untrustedDossier(), $dossier['notice']);
		self::assertSame(['events', 'card', 'links', 'folders', 'search', 'search', 'search', 'placed', 'read'], array_column($ports->calls, 0));
		foreach ($ports->calls as [$name, $arguments]) {
			self::assertContains($name, ['events', 'card', 'links', 'read', 'search', 'folders', 'placed']);
			self::assertArrayNotHasKey('body', $arguments);
		}
	}

	/**
	 * A cut calendar window is copied, and the next event is not claimed to be the first.
	 *
	 * @return void
	 */
	public function testACutWindowSaysTheNextEventMayNotBeTheFirst(): void {
		$item = self::occurrence();
		$ports = new PreparationDouble(['content' => [
			['type' => 'text', 'text' => json_encode([$item], JSON_THROW_ON_ERROR)],
			['type' => 'text', 'text' => json_encode(['truncated' => true, 'limit' => 2000], JSON_THROW_ON_ERROR)],
		]]);
		$dossier = $this->dossier($this->tool($ports, ['calendar.read']), self::window());
		self::assertSame(['truncated' => true, 'limit' => 2000], $dossier['window']);
		self::assertSame(RoutinesMessages::windowCut(), $dossier['windowNotice']);
		self::assertSame($item['uid'], $dossier['event']['uid']);
		$named = $this->dossier($this->tool($ports, ['calendar.read']), self::window(['uid' => $item['uid'], 'calendar' => $item['calendar']]));
		self::assertSame(['truncated' => true, 'limit' => 2000], $named['window']);
		self::assertArrayNotHasKey('windowNotice', $named);
	}

	/**
	 * Naming an event takes the calendar and the UID together. A reader error is not reported as an empty window.
	 *
	 * @return void
	 */
	public function testCalendarAndUidAreNamedTogetherAndAReaderErrorIsRefused(): void {
		$ports = new PreparationDouble(['isError' => true, 'content' => []]);
		try {
			$this->tool($ports, self::ON)->execute(self::window(['uid' => 'evt-1']), new ToolContext('alice'));
			self::fail('uid without calendar must be refused');
		} catch (\InvalidArgumentException $error) {
			self::assertSame(RoutinesMessages::calendarAndUid(), $error->getMessage());
		}
		self::assertSame([], $ports->calls);
		try {
			$this->tool($ports, self::ON)->execute(self::window(), new ToolContext('alice'));
			self::fail('a calendar error must be refused');
		} catch (ToolFailure $error) {
			self::assertSame(RoutinesMessages::calendarUnreadable(), $error->getMessage());
		}
	}

	/**
	 * @param list<string> $grants module.operation pairs that are granted
	 * @return PrepareMeeting the tool over the double
	 */
	private function tool(PreparationDouble $ports, array $grants): PrepareMeeting {
		$policy = $this->createMock(GrantPolicy::class);
		$policy->method('granted')->willReturnCallback(
			static fn (string $uid, string $module, string $operation): bool => in_array($module . '.' . $operation, $grants, true),
		);
		$sources = new PreparationSources($ports, $ports, $ports, $ports);
		$roster = new ParticipantRoster($policy, new AccountEmailMatch($this->createMock(ISearch::class)));
		return new PrepareMeeting(new PrepareMeetingHandler(
			$sources,
			$policy,
			$roster,
			new MailCandidates($sources, $policy),
			new CardCandidates($sources, $policy, $this->link()),
			new FileCandidates($sources, $policy, $this->link()),
		));
	}

	/**
	 * @return array<string, mixed> the decoded dossier
	 */
	private function dossier(PrepareMeeting $tool, array $arguments): array {
		$result = $tool->execute($arguments, new ToolContext('alice'));
		$decoded = json_decode($result['content'][0]['text'], true);
		self::assertIsArray($decoded);
		return $decoded;
	}

	/**
	 * @return array<string, mixed> one occurrence the reader already expanded
	 */
	private static function occurrence(string $uid = 'evt-1', string $start = '2026-04-09T10:00:00Z', string $end = '2026-04-09T11:00:00Z'): array {
		return ['uid' => $uid, 'summary' => 'Planning', 'start' => $start, 'end' => $end,
			'calendar' => '/calendars/alice/personal/', 'location' => '', 'allDay' => false];
	}

	/**
	 * @param array<string, mixed> $extra arguments added to the window
	 * @return array<string, mixed> a window that contains 9 April 2026 and not 2 April
	 */
	private static function window(array $extra = []): array {
		return $extra + ['from' => '2026-04-08T00:00:00Z', 'to' => '2026-04-10T00:00:00Z'];
	}
}
