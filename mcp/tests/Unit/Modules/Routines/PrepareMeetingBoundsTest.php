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
use OCA\Mcp\Modules\Calendar\Integration\MeetingEventFacts;
use OCA\Mcp\Modules\Routines\Preparation\AccountEmailMatch;
use OCA\Mcp\Modules\Routines\Preparation\CardCandidates;
use OCA\Mcp\Modules\Routines\Preparation\DossierBudget;
use OCA\Mcp\Modules\Routines\Preparation\FileCandidates;
use OCA\Mcp\Modules\Routines\Preparation\MailCandidates;
use OCA\Mcp\Modules\Routines\Preparation\ParticipantRoster;
use OCA\Mcp\Modules\Routines\Preparation\PrepareMeetingHandler;
use OCA\Mcp\Modules\Routines\Preparation\ReferenceTexts;
use OCA\Mcp\Modules\Routines\Presentation\RoutinesSchema;
use OCA\Mcp\Modules\Routines\Tools\PrepareMeeting;
use OCA\Mcp\Platform\Meetings\PreparationSources;
use OCP\Collaboration\Collaborators\ISearch;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Reader;

/** Hosts that are not this instance, and the ceiling on file lookups. */
final class PrepareMeetingBoundsTest extends TestCase {
	use BuildsCloudLink;

	/** @var list<string> grants of a dossier that may consult every source */
	private const ON = ['calendar.read', 'mail.messages', 'deck.read', 'files.read', 'people.read'];

	/**
	 * A foreign host is not a card or a file of this instance. A relative path still is.
	 *
	 * @return void
	 */
	public function testAForeignHostIsNotResolvedAsThisInstance(): void {
		$local = $this->link();
		self::assertSame([], ReferenceTexts::deckCardIds('https://evil.example/apps/deck/board/1/card/77', $local));
		self::assertSame([8], ReferenceTexts::deckCardIds('https://cloud.example/apps/deck/board/4/card/8', $local));
		self::assertSame([8], ReferenceTexts::deckCardIds('/apps/deck/board/4/card/8', $local));
		self::assertFalse(ReferenceTexts::isFile('https://outro.example/f/123', $local));
		self::assertFalse(ReferenceTexts::isFile('https://evil.example/apps/files/?fileid=9', $local));
		self::assertTrue(ReferenceTexts::isFile('https://cloud.example/f/15', $local));
		self::assertTrue(ReferenceTexts::isFile('/Notes/a.md', $local));
		$backslash = 'https://evil.com\@cloud.example.org/f/1';
		self::assertFalse($local->accepts($backslash));
		self::assertSame([], ReferenceTexts::fileReferences($backslash, $local));
		foreach ([
			'https://evil.com//f/123',
			'https://evil.com/share/-/f/123',
			'https://evil.com/?x=/f/9',
			'https://evil.com/x//apps/files/?fileid=5',
		] as $foreign) {
			self::assertSame([], ReferenceTexts::fileReferences($foreign, $local), $foreign);
		}
		self::assertSame([], ReferenceTexts::deckCardIds('//evil.com/apps/deck/board/1/card/3', $local));
		self::assertSame([], ReferenceTexts::deckCardIds('ftp://evil.com/apps/deck/board/1/card/3', $local));
		self::assertSame([], ReferenceTexts::deckCardIds('partner.cloud/apps/deck/#/board/1/card/2', $local));
		self::assertSame([8], ReferenceTexts::deckCardIds('//cloud.example/apps/deck/board/4/card/8', $local));
		self::assertSame([8], ReferenceTexts::deckCardIds(' /apps/deck/board/4/card/8', $local));
		self::assertSame(['https://cloud.example/f/15'], ReferenceTexts::fileReferences('https://cloud.example/f/15', $local));
		self::assertSame(['/f/15'], ReferenceTexts::fileReferences(' /f/15', $local));
		self::assertSame(['/f/15'], ReferenceTexts::fileReferences('"/f/15"', $local));
		self::assertSame(['/f/15'], ReferenceTexts::fileReferences("'/f/15'", $local));
		self::assertSame(['https://cloud.example/a\'/f/15'], ReferenceTexts::fileReferences('https://cloud.example/a\'/f/15', $local));
		self::assertStringContainsString(
			'Deck assignments of a local account are the first 20 rows the mapper returns for that account, in an undefined order',
			RoutinesSchema::PREPARE_NOTE,
		);
	}

	/**
	 * The event uses the same tokenizer. A foreign URL stays one token and is not a link of this instance.
	 *
	 * @return void
	 */
	public function testEventTextKeepsAnAbsoluteUrlWhole(): void {
		$calendar = Reader::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\n"
			. "UID:meet\r\nSUMMARY:Planning\r\nDTSTART:20260409T100000Z\r\nDTEND:20260409T110000Z\r\n"
			. "URL:ftp://evil.com/apps/deck/board/1/card/3\r\n"
			. "URL:https://cloud.example/apps/deck/board/4/card/8\r\n"
			. "ATTACH:/f/22\r\n"
			. "DESCRIPTION:https://evil.com//f/123 https://evil.com/'/f/27 https://cloud.example/f/15 /f/16\r\n"
			. "END:VEVENT\r\nEND:VCALENDAR\r\n");
		$facts = (new MeetingEventFacts($this->link()))->of($calendar->VEVENT);
		self::assertContains('https://cloud.example/apps/deck/board/4/card/8', $facts['links']);
		self::assertContains('https://cloud.example/f/15', $facts['links']);
		self::assertContains('/f/22', $facts['links']);
		self::assertContains('/f/16', $facts['links']);
		self::assertNotContains('/f/123', $facts['links']);
		self::assertNotContains('/f/27', $facts['links']);
		self::assertNotContains('https://evil.com/', $facts['links']);
		self::assertNotContains("https://evil.com/'/f/27", $facts['links']);
		self::assertNotContains('ftp://evil.com/apps/deck/board/1/card/3', $facts['links']);
	}

	/**
	 * One folder the reader cannot search is skipped. Messages from the other folder stay, and the failure text does not.
	 *
	 * @return void
	 */
	public function testAFolderThatCannotBeReadDoesNotDropTheOtherFolders(): void {
		$ports = new PreparationDouble(
			PreparationDouble::json([self::event(['attendees' => ['ada@example.com']])]),
			static function (string $userId, array $arguments): array {
				if ((int)$arguments['mailbox_id'] === 3) {
					throw new ToolFailure('mailbox unreadable: synchronizing');
				}
				return PreparationDouble::json(['messages' => [[
					'id' => 7, 'subject' => 'Agenda', 'date' => '2026-04-01', 'url' => 'https://cloud.example/mail/7', 'mailboxId' => 4,
					'from' => ['count' => 1, 'addresses' => [['name' => 'Ada', 'email' => 'ada@example.com']]],
				]]]);
			},
		);
		$ports->folders = [3, 4];
		$dossier = $this->dossier($this->tool($ports, self::ON), self::window());
		self::assertSame('consulted', $dossier['sources']['mail']['status']);
		self::assertSame('exact_address', $dossier['sources']['mail']['reason']);
		self::assertTrue($dossier['sources']['mail']['truncated']);
		self::assertSame([7], array_column($dossier['sources']['mail']['candidates'], 'id'));
		$encoded = json_encode($dossier, JSON_THROW_ON_ERROR);
		self::assertStringNotContainsString('synchronizing', $encoded);
		self::assertStringNotContainsString('unreadable', $encoded);
	}

	/**
	 * More file references than the lookup ceiling are not all resolved, and the cut is marked.
	 *
	 * @return void
	 */
	public function testFileReferencesStopAtTheLookupCeiling(): void {
		$links = [];
		for ($i = 1; $i <= 41; $i++) {
			$links[] = '/Notes/f' . $i . '.md';
		}
		$ports = new PreparationDouble(
			PreparationDouble::json([self::event(['links' => $links])]),
			null,
			null,
			null,
			static fn (string $userId, array $arguments): array => ['content' => [
				['type' => 'text', 'text' => 'x'],
				['type' => 'text', 'text' => json_encode(['path' => $arguments['path'], 'etag' => 'e'], JSON_THROW_ON_ERROR)],
			]],
			null,
			static fn (string $userId, string $reference): ?string => $reference,
		);
		$dossier = $this->dossier($this->tool($ports, self::ON), self::window());
		$placed = array_values(array_filter($ports->calls, static fn (array $call): bool => $call[0] === 'placed'));
		self::assertCount(DossierBudget::MAX_REFERENCE_LOOKUPS, $placed);
		self::assertTrue($dossier['sources']['files']['truncated']);
		self::assertLessThanOrEqual(DossierBudget::MAX_FILES, count($dossier['sources']['files']['candidates']));
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
			$sources, $policy, $roster, new MailCandidates($sources, $policy), new CardCandidates($sources, $policy, $this->link()), new FileCandidates($sources, $policy, $this->link()),
		));
	}

	/**
	 * @param array<string, mixed> $arguments the window
	 * @return array<string, mixed> the decoded dossier
	 */
	private function dossier(PrepareMeeting $tool, array $arguments): array {
		$result = $tool->execute($arguments, new ToolContext('alice'));
		$decoded = json_decode($result['content'][0]['text'], true);
		self::assertIsArray($decoded);
		return $decoded;
	}

	/**
	 * @param array<string, mixed> $extra fields the reader attached
	 * @return array<string, mixed> one occurrence
	 */
	private static function event(array $extra = []): array {
		return $extra + ['uid' => 'evt-1', 'summary' => 'Planning', 'start' => '2026-04-09T10:00:00Z', 'end' => '2026-04-09T11:00:00Z',
			'calendar' => '/calendars/alice/personal/', 'location' => '', 'allDay' => false];
	}

	/**
	 * @return array<string, mixed> the window of the dossier
	 */
	private static function window(): array {
		return ['from' => '2026-04-08T00:00:00Z', 'to' => '2026-04-10T00:00:00Z'];
	}
}
