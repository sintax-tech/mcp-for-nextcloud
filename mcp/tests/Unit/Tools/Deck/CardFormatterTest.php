<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\Stack;
use OCA\Mcp\Tools\Deck\CardFormatter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/deck_stubs.php';

/**
 * Covers the Deck entity to payload conversion.
 */
final class CardFormatterTest extends TestCase {
	use DeckTestHelpers;

	private IURLGenerator $urls;

	private ITimeFactory $time;

	private IUserManager $users;

	private CardFormatter $formatter;

	protected function setUp(): void {
		$this->urls = $this->createMock(IURLGenerator::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->formatter = new CardFormatter($this->urls, $this->time, $this->users);
	}

	public function testBoardExposesOnlyTheContractedFields(): void {
		$board = new Board([
			'id' => 4,
			'title' => 'Casa',
			'color' => '0082c9',
			'archived' => false,
			'owner' => 'alice',
			'lastModified' => 1_700_000_001,
		]);

		self::assertSame([
			'id' => 4,
			'title' => 'Casa',
			'color' => '0082c9',
			'archived' => false,
			'owner' => 'alice',
			'lastModified' => 1_700_000_001,
		], $this->formatter->board($board));
	}

	public function testStackCountsTheEmbeddedCards(): void {
		$stack = new Stack([
			'id' => 10,
			'boardId' => 4,
			'title' => 'A fazer',
			'order' => 1,
			'cards' => [$this->card(['id' => 1]), $this->card(['id' => 2])],
		]);

		self::assertSame([
			'id' => 10,
			'boardId' => 4,
			'title' => 'A fazer',
			'order' => 1,
			'cardsCount' => 2,
		], $this->formatter->stack($stack));
	}

	public function testStackWithoutCardsReportsZero(): void {
		$stack = new Stack(['id' => 11, 'boardId' => 4, 'title' => 'Concluído', 'order' => 2]);

		self::assertSame(0, $this->formatter->stack($stack)['cardsCount']);
	}

	public function testCardFormatsDatesAndUsesTheGivenBoard(): void {
		$card = $this->card([
			'id' => 7,
			'stackId' => 10,
			'title' => 'Fechar contrato',
			'description' => 'Com o cliente',
			'duedate' => new \DateTime('2026-03-01 00:00:00'),
			'done' => new \DateTime('2026-02-01 09:30:00'),
			'attachmentCount' => 2,
		]);

		$formatted = $this->formatter->card($card, 4);

		self::assertSame(7, $formatted['id']);
		self::assertSame(4, $formatted['boardId']);
		self::assertSame('2026-03-01', $formatted['duedate']);
		self::assertSame((new \DateTime('2026-02-01 09:30:00'))->getTimestamp(), $formatted['done']);
		self::assertSame(2, $formatted['attachmentCount']);
	}

	public function testCardFallsBackToTheBoardDeckEnriched(): void {
		$card = $this->card(['relatedBoard' => new Board(['id' => 9])]);

		self::assertSame(9, $this->formatter->card($card)['boardId']);
	}

	public function testCardWithoutDatesReportsNull(): void {
		$formatted = $this->formatter->card($this->card());

		self::assertNull($formatted['duedate']);
		self::assertNull($formatted['done']);
		self::assertNull($formatted['url']);
	}

	public function testActiveCardIsListed(): void {
		self::assertTrue($this->formatter->isListed($this->card()));
	}

	public function testDeletedCardIsNotListed(): void {
		self::assertFalse($this->formatter->isListed($this->card(['deletedAt' => 1_700_000_500])));
	}

	public function testArchivedCardIsNotListed(): void {
		self::assertFalse($this->formatter->isListed($this->card(['archived' => true])));
	}

	public function testCardExposesThePeopleAssignedToItWithTheirNames(): void {
		$alice = $this->createMock(IUser::class);
		$alice->method('getDisplayName')->willReturn('Alice Silva');
		// `pedro` has no account, so the UID itself is what the caller sees.
		$this->users->method('get')->willReturnCallback(
			static fn (string $uid): ?IUser => $uid === 'alice' ? $alice : null,
		);
		$card = $this->card(['id' => 7, 'assignedUsers' => [
			new Assignment(['cardId' => 7, 'participant' => 'alice', 'type' => Acl::PERMISSION_TYPE_USER]),
			// A group or a circle names no person, so it never reaches the payload as an owner.
			new Assignment(['cardId' => 7, 'participant' => 'vendas', 'type' => Acl::PERMISSION_TYPE_GROUP]),
			new Assignment(['cardId' => 7, 'participant' => 'pedro', 'type' => Acl::PERMISSION_TYPE_USER]),
		]]);

		$formatted = $this->formatter->card($card, 4);

		self::assertSame(
			[
				['uid' => 'alice', 'displayName' => 'Alice Silva'],
				['uid' => 'pedro', 'displayName' => 'pedro'],
			],
			$formatted['assignedUsers'],
		);
	}

	public function testCardNobodyIsAssignedToCarriesAnEmptyList(): void {
		self::assertSame([], $this->formatter->card($this->card())['assignedUsers']);
	}

	public function testCardResolvesOneDisplayNamesOncePerRequest(): void {
		$alice = $this->createMock(IUser::class);
		$alice->method('getDisplayName')->willReturn('Alice Silva');
		$this->users->expects(self::once())->method('get')->with('alice')->willReturn($alice);
		$assignments = [new Assignment(['participant' => 'alice', 'type' => Acl::PERMISSION_TYPE_USER])];

		$this->formatter->card($this->card(['id' => 1, 'assignedUsers' => $assignments]), 4);
		$formatted = $this->formatter->card($this->card(['id' => 2, 'assignedUsers' => $assignments]), 4);

		self::assertSame('Alice Silva', $formatted['assignedUsers'][0]['displayName']);
	}

	public function testOverdueIsDecidedByTheInjectedClock(): void {
		$this->time->method('getTime')->willReturn((new \DateTime('2026-03-05 12:00:00'))->getTimestamp());

		self::assertTrue($this->formatter->card($this->card([
			'id' => 1,
			'duedate' => new \DateTime('2026-03-01 00:00:00'),
		]), 4)['overdue']);
		// Due today is still due today: the boundary is the day, not the hour.
		self::assertFalse($this->formatter->card($this->card([
			'id' => 2,
			'duedate' => new \DateTime('2026-03-05 00:00:00'),
		]), 4)['overdue']);
		self::assertFalse($this->formatter->card($this->card([
			'id' => 3,
			'duedate' => new \DateTime('2026-03-06 00:00:00'),
		]), 4)['overdue']);
	}

	public function testFinishedOrArchivedCardIsNeverOverdue(): void {
		$this->time->method('getTime')->willReturn((new \DateTime('2026-03-05 12:00:00'))->getTimestamp());
		$late = ['duedate' => new \DateTime('2026-03-01 00:00:00')];

		self::assertFalse($this->formatter->card($this->card($late + ['id' => 1, 'done' => new \DateTime('2026-03-02 09:00:00')]), 4)['overdue']);
		self::assertFalse($this->formatter->card($this->card($late + ['id' => 2, 'archived' => true]), 4)['overdue']);
		self::assertFalse($this->formatter->card($this->card(['id' => 3]), 4)['overdue']);
	}

	public function testCardCarriesTheAbsoluteLinkToTheDeckCardPage(): void {
		$this->urls->expects(self::once())
			->method('linkToRouteAbsolute')
			->with('deck.page.indexCard', ['boardId' => 4, 'cardId' => 7])
			->willReturn('https://cloud.example/apps/deck/board/4/card/7');

		self::assertSame(
			'https://cloud.example/apps/deck/board/4/card/7',
			$this->formatter->card($this->card(['id' => 7]), 4)['url'],
		);
	}

	public function testDuedateAndOverdueSpeakTheCallerDayNearMidnight(): void {
		// 23:30 of 2026-09-30 in São Paulo; the card is due at 22:00 that same local day.
		$this->time->method('getTime')->willReturn((new \DateTimeImmutable('2026-10-01 02:30:00', new \DateTimeZone('UTC')))->getTimestamp());
		$formatter = new CardFormatter($this->urls, $this->time, $this->users, new \DateTimeZone('America/Sao_Paulo'));

		$formatted = $formatter->card($this->card(['duedate' => new \DateTime('2026-10-01 01:00:00', new \DateTimeZone('UTC'))]), 4);

		self::assertSame('2026-09-30', $formatted['duedate']);
		self::assertFalse($formatted['overdue']);
	}
}
