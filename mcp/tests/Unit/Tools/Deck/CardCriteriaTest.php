<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use OCA\Mcp\Tools\Deck\CardCriteria;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/deck_stubs.php';

/**
 * Covers the day boundary of `overdue` and `dueBefore`: Deck stores `duedate` in UTC, the user reads it in
 * their own timezone, so near midnight the two calendars disagree and only the user's one is right.
 */
final class CardCriteriaTest extends TestCase {
	use DeckTestHelpers;

	private \DateTimeZone $saoPaulo;

	protected function setUp(): void {
		$this->saoPaulo = new \DateTimeZone('America/Sao_Paulo');
	}

	public function testCardDueTodayIsNotOverdueLateAtNightInTheUserTimezone(): void {
		// 23:30 of 2026-09-30 in São Paulo is already 2026-10-01 in UTC.
		$now = $this->instant('2026-10-01 02:30:00');
		// Due 2026-09-30 22:00 in São Paulo, stored as 2026-10-01 01:00 UTC.
		$card = $this->card(['duedate' => new \DateTime('2026-10-01 01:00:00', new \DateTimeZone('UTC'))]);

		self::assertFalse(CardCriteria::isOverdue($card, $now, $this->saoPaulo));
		self::assertSame('2026-09-30', CardCriteria::dueDay($card, $this->saoPaulo));
	}

	public function testCardDueYesterdayTurnsOverdueJustAfterLocalMidnight(): void {
		// 00:30 of 2026-10-01 in São Paulo.
		$now = $this->instant('2026-10-01 03:30:00');
		$card = $this->card(['duedate' => new \DateTime('2026-10-01 01:00:00', new \DateTimeZone('UTC'))]);

		self::assertTrue(CardCriteria::isOverdue($card, $now, $this->saoPaulo));
	}

	public function testUtcReadingOfTheSameInstantWouldDisagree(): void {
		// Guard of the fix: in UTC the same card is already overdue at 02:30, which is exactly the bug.
		$now = $this->instant('2026-10-02 02:30:00');
		$card = $this->card(['duedate' => new \DateTime('2026-10-01 01:00:00', new \DateTimeZone('UTC'))]);

		self::assertTrue(CardCriteria::isOverdue($card, $now, new \DateTimeZone('UTC')));
		self::assertTrue(CardCriteria::isOverdue($card, $now, $this->saoPaulo));
		self::assertFalse(CardCriteria::isOverdue($card, $this->instant('2026-10-01 02:30:00'), $this->saoPaulo));
	}

	public function testDueBeforeComparesTheDayTheUserSees(): void {
		$now = $this->instant('2026-09-01 12:00:00');
		$card = $this->card(['duedate' => new \DateTime('2026-10-01 01:00:00', new \DateTimeZone('UTC'))]);

		// The user sees 2026-09-30, so a bound of 2026-09-30 keeps it even though UTC says 2026-10-01.
		self::assertTrue(CardCriteria::matches($card, CardCriteria::STATUS_ALL, '2026-09-30', $now, $this->saoPaulo));
		self::assertFalse(CardCriteria::matches($card, CardCriteria::STATUS_ALL, '2026-09-29', $now, $this->saoPaulo));
	}

	public function testDayWrittenByTheToolsIsLocalMidnightAndReadsBackAsTheSameDay(): void {
		$stored = CardCriteria::localMidnight('2026-10-01', $this->saoPaulo);

		self::assertSame('2026-10-01T00:00:00-03:00', $stored);
		// Deck parses the string into a DateTime and keeps it in UTC.
		$card = $this->card(['duedate' => (new \DateTime($stored))->setTimezone(new \DateTimeZone('UTC'))]);
		self::assertSame('2026-10-01', CardCriteria::dueDay($card, $this->saoPaulo));
	}

	/**
	 * @param string $utc wall clock in UTC
	 * @return int unix timestamp of that instant
	 */
	private function instant(string $utc): int {
		return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->getTimestamp();
	}
}
