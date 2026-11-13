<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Routines;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Modules\Routines\Preparation\AccountEmailMatch;
use OCA\Mcp\Modules\Routines\Preparation\CardCandidates;
use OCA\Mcp\Modules\Routines\Preparation\DossierBudget;
use OCA\Mcp\Modules\Routines\Preparation\FileCandidates;
use OCA\Mcp\Modules\Routines\Preparation\MailCandidates;
use OCA\Mcp\Modules\Routines\Preparation\ParticipantRoster;
use OCA\Mcp\Modules\Routines\Preparation\PrepareMeetingHandler;
use OCA\Mcp\Modules\Routines\Preparation\ReferenceTexts;
use OCA\Mcp\Modules\Routines\Tools\PrepareMeeting;
use OCA\Mcp\Platform\Meetings\PreparationSources;
use OCP\Collaboration\Collaborators\ISearch;
use PHPUnit\Framework\TestCase;

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
