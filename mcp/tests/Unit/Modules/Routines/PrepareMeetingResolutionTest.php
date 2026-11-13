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
use OCA\Mcp\Modules\Routines\Preparation\AccountEmailMatch;
use OCA\Mcp\Modules\Routines\Preparation\CardCandidates;
use OCA\Mcp\Modules\Routines\Preparation\FileCandidates;
use OCA\Mcp\Modules\Routines\Preparation\MailCandidates;
use OCA\Mcp\Modules\Routines\Preparation\ParticipantRoster;
use OCA\Mcp\Modules\Routines\Preparation\PrepareMeetingHandler;
use OCA\Mcp\Modules\Routines\Tools\PrepareMeeting;
use OCA\Mcp\Platform\Meetings\PreparationSources;
use OCP\Collaboration\Collaborators\ISearch;
use PHPUnit\Framework\TestCase;

/** Who becomes a local account, and which card is left out. */
final class PrepareMeetingResolutionTest extends TestCase {
	use BuildsCloudLink;
	/**
	 * Without people.read the address stays data. Assigned cards are not looked up.
	 *
	 * @return void
	 */
	public function testAParticipantWithoutPeopleReadDoesNotBecomeAUid(): void {
		$ports = new PreparationDouble(PreparationDouble::json([self::event()]));
		$dossier = $this->dossier($ports, ['calendar.read', 'deck.read'], null);
		self::assertNull($dossier['participants'][0]['uid']);
		self::assertSame('not_consulted', $dossier['participants'][0]['account']);
		self::assertNotContains('assigned', array_column($ports->calls, 0));
	}

	/**
	 * Two exact accounts for one address are not a UID, and assigned cards are not looked up for them.
	 *
	 * @return void
	 */
	public function testAnAmbiguousAccountDoesNotBecomeAUid(): void {
		$search = $this->createMock(ISearch::class);
		$search->method('search')->willReturn([['exact' => ['users' => [
			['value' => ['shareWith' => 'ana'], 'subline' => 'ada@example.com'],
			['value' => ['shareWith' => 'ada'], 'subline' => 'ada@example.com'],
		]]], false]);
		$ports = new PreparationDouble(PreparationDouble::json([self::event()]));
		$dossier = $this->dossier($ports, ['calendar.read', 'people.read', 'deck.read'], $search);
		self::assertNull($dossier['participants'][0]['uid']);
		self::assertSame('exact_or_none', $dossier['participants'][0]['account']);
		self::assertNotContains('assigned', array_column($ports->calls, 0));
	}

	/**
	 * A card URL on a board the actor cannot open is omitted, and the refusal does not reveal a count or the id.
	 *
	 * @return void
	 */
	public function testACardOnAnInaccessibleBoardIsOmittedWithoutACount(): void {
		$ports = new PreparationDouble(
			PreparationDouble::json([self::event(['links' => ['https://cloud.example/apps/deck/board/9/card/404']])]),
			null,
			static function (string $userId, array $arguments): array {
				return ['content' => [['type' => 'text', 'text' => 'You do not have permission']], 'isError' => true];
			},
		);
		$dossier = $this->dossier($ports, ['calendar.read', 'deck.read'], null);
		self::assertSame([], $dossier['sources']['deck']['candidates']);
		self::assertFalse($dossier['sources']['deck']['truncated']);
		$encoded = json_encode($dossier, JSON_THROW_ON_ERROR);
		self::assertStringNotContainsString('404', $encoded);
		self::assertStringNotContainsString('board refused', $encoded);
		self::assertStringNotContainsString('"omitted"', $encoded);
		self::assertArrayNotHasKey('count', $dossier['sources']['deck']);
	}

	/**
	 * Assignments the reader refuses do not become cards and do not mark the budget as cut.
	 *
	 * @return void
	 */
	public function testRefusedAssignmentsDoNotOccupyTheCardBudget(): void {
		$search = $this->createMock(ISearch::class);
		$search->method('search')->willReturn([['exact' => ['users' => [
			['value' => ['shareWith' => 'pedro'], 'subline' => 'ada@example.com'],
		]]], false]);
		$ports = new PreparationDouble(
			PreparationDouble::json([self::event()]),
			null,
			static fn (string $userId, array $arguments): array => ['content' => [['type' => 'text', 'text' => 'You do not have permission']], 'isError' => true],
			null,
			null,
			static fn (string $userId, string $participantUid): array => range(1, 25),
		);
		$dossier = $this->dossier($ports, ['calendar.read', 'people.read', 'deck.read'], $search);
		self::assertSame([], $dossier['sources']['deck']['candidates']);
		self::assertFalse($dossier['sources']['deck']['truncated']);
		self::assertStringNotContainsString('cardId', json_encode($dossier, JSON_THROW_ON_ERROR));
	}

	/**
	 * The twenty-first card that the actor can read is omitted and the cut is marked. Its id is not echoed.
	 *
	 * @return void
	 */
	public function testTheCardBudgetCountsOnlyCardsTheActorCanRead(): void {
		$search = $this->createMock(ISearch::class);
		$search->method('search')->willReturn([['exact' => ['users' => [
			['value' => ['shareWith' => 'pedro'], 'subline' => 'ada@example.com'],
		]]], false]);
		$ports = new PreparationDouble(
			PreparationDouble::json([self::event()]),
			null,
			static fn (string $userId, array $arguments): array => PreparationDouble::json([
				'id' => $arguments['cardId'], 'title' => 'Card', 'lastModified' => 1710000000,
			]),
			static fn (string $userId, array $arguments): array => PreparationDouble::json(['items' => []]),
			null,
			static fn (string $userId, string $participantUid): array => range(1, 21),
		);
		$dossier = $this->dossier($ports, ['calendar.read', 'people.read', 'deck.read'], $search);
		self::assertSame(range(1, 20), array_column($dossier['sources']['deck']['candidates'], 'cardId'));
		self::assertTrue($dossier['sources']['deck']['truncated']);
		self::assertSame(1710000000, $dossier['sources']['deck']['candidates'][0]['lastModified']);
		self::assertStringNotContainsString('"cardId": 21', json_encode($dossier, JSON_THROW_ON_ERROR));
	}

	/**
	 * @param list<string> $grants module.operation pairs that are granted
	 * @param ISearch|null $search the people search, empty when people.read is off
	 * @return array<string, mixed> the decoded dossier
	 */
	private function dossier(PreparationDouble $ports, array $grants, ?ISearch $search): array {
		$policy = $this->createMock(GrantPolicy::class);
		$policy->method('granted')->willReturnCallback(
			static fn (string $uid, string $module, string $operation): bool => in_array($module . '.' . $operation, $grants, true),
		);
		$sources = new PreparationSources($ports, $ports, $ports, $ports);
		$tool = new PrepareMeeting(new PrepareMeetingHandler(
			$sources,
			$policy,
			new ParticipantRoster($policy, new AccountEmailMatch($search ?? $this->createMock(ISearch::class))),
			new MailCandidates($sources, $policy),
			new CardCandidates($sources, $policy, $this->link()),
			new FileCandidates($sources, $policy, $this->link()),
		));
		$result = $tool->execute(['from' => '2026-04-08T00:00:00Z', 'to' => '2026-04-10T00:00:00Z'], new ToolContext('alice'));
		$decoded = json_decode($result['content'][0]['text'], true);
		self::assertIsArray($decoded);
		return $decoded;
	}

	/**
	 * @param array<string, mixed> $extra fields added to the occurrence
	 * @return array<string, mixed> one occurrence the reader already returned
	 */
	private static function event(array $extra = []): array {
		return $extra + [
			'uid' => 'evt-1', 'summary' => 'Planning', 'start' => '2026-04-09T10:00:00Z', 'end' => '2026-04-09T11:00:00Z',
			'calendar' => '/calendars/alice/personal/', 'location' => '', 'allDay' => false,
			'attendees' => [['email' => 'ada@example.com', 'name' => 'Ada']],
		];
	}
}
