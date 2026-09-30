<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Deck\Db\Board;
use OCA\Deck\Db\Stack;
use OCA\Mcp\Tools\Deck\CardFormatter;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/deck_stubs.php';

/**
 * Covers the Deck entity to payload conversion.
 */
final class CardFormatterTest extends TestCase {
	use DeckTestHelpers;

	private CardFormatter $formatter;

	protected function setUp(): void {
		$this->formatter = new CardFormatter();
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
}
