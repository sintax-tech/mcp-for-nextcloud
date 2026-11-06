<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Mcp\Modules\Deck\Structure\BoardBlueprint;
use OCA\Mcp\Modules\Deck\DueDates\CardDueDate;
use OCA\Mcp\Modules\Deck\DueDates\CardDueDateView;
use OCA\Mcp\Modules\Deck\Editing\CardInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Strict public timestamps, DST resolution and account-local presentation share one instant contract.
 */
final class CardDueTimeTest extends TestCase {
	/**
	 * Supply independent wire expectations, including clocks unlike the account timezone.
	 *
	 * @return iterable<string, array{string, string, string}> Input, account zone and expected UTC.
	 */
	public static function times(): iterable {
		yield 'Brazil minutes' => ['2026-10-01T15:30', 'America/Sao_Paulo', '2026-10-01T18:30:00Z'];
		yield 'Brazil seconds' => ['2026-10-01T15:30:19', 'America/Sao_Paulo', '2026-10-01T18:30:19Z'];
		yield 'Japan' => ['2026-10-01T15:30', 'Asia/Tokyo', '2026-10-01T06:30:00Z'];
		yield 'Japan previous day' => ['2026-10-01T00:15', 'Asia/Tokyo', '2026-09-30T15:15:00Z'];
		yield 'negative zero offset' => ['2026-10-01T10:00-00:00', 'America/Sao_Paulo', '2026-10-01T10:00:00Z'];
		yield 'Z' => ['2026-10-01T15:30Z', 'Asia/Tokyo', '2026-10-01T15:30:00Z'];
		yield 'positive offset' => ['2026-10-01T15:30:19+02:00', 'America/Sao_Paulo', '2026-10-01T13:30:19Z'];
		yield 'negative offset' => ['2026-10-01T15:30-05:00', 'Asia/Tokyo', '2026-10-01T20:30:00Z'];
		yield 'winter' => ['2026-01-15T15:30', 'America/New_York', '2026-01-15T20:30:00Z'];
		yield 'summer' => ['2026-07-15T15:30', 'America/New_York', '2026-07-15T19:30:00Z'];
		yield 'New York fold' => ['2026-11-01T01:30', 'America/New_York', '2026-11-01T05:30:00Z'];
		yield 'Berlin fold' => ['2026-10-25T02:30', 'Europe/Berlin', '2026-10-25T00:30:00Z'];
		yield 'Havana midnight fold' => ['2026-11-01T00:00', 'America/Havana', '2026-11-01T04:00:00Z'];
		yield 'explicit offset during local gap' => ['2026-09-06T00:00-04:00', 'America/Santiago', '2026-09-06T04:00:00Z'];
	}

	/**
	 * Validate syntax without changing spelling, then normalize every accepted instant for native Deck.
	 *
	 * @param string $input Public input.
	 * @param string $zone Account timezone.
	 * @param string $utc Independent UTC expectation.
	 * @return void
	 */
	#[DataProvider('times')]
	public function testUtcStorage(string $input, string $zone, string $utc): void {
		$account = new DateTimeZone($zone);
		self::assertSame($input, CardInput::duedate($input, $account));
		self::assertSame($utc, CardDueDate::forDeck($input, $account));
		self::assertTrue(CardDueDate::same(new DateTimeImmutable($utc), $input, $account));
		self::assertFalse(CardDueDate::same((new DateTimeImmutable($utc))->modify('+1 second'), $input, $account));
	}

	/**
	 * Enumerate malformed or impossible public values instead of relying on PHP's permissive parser.
	 *
	 * @return iterable<array{mixed}> Rejected values.
	 */
	public static function invalid(): iterable {
		foreach ([true, 123, [], '', ' 2026-10-01', "2026-10-01\n", '2026-2-01', '2026-02-29',
			'2026-10-01 15:30', '2026-10-01T5:30', '2026-10-01T24:00', '2026-10-01T12:60',
			'2026-10-01T12:00:60', '2026-10-01T12:00z', '2026-10-01T12:00:00.1Z',
			'2026-10-01T12:00+24:00', '2026-10-01T12:00+00:60', '2026-10-01T12:00+0000',
			'2026-10-01T12:00 UTC', '2026-13-01T12:00', '2026-04-31T12:00'] as $value) {
			yield [$value];
		}
	}

	/**
	 * Refuse unsupported values at the public validation boundary.
	 *
	 * @param mixed $input Invalid public value.
	 * @return void
	 */
	#[DataProvider('invalid')]
	public function testInvalidInput(mixed $input): void {
		$this->expectException(InvalidArgumentException::class);
		CardInput::duedate($input, new DateTimeZone('UTC'));
	}

	/**
	 * Supply real spring transitions whose local clock fields never occurred.
	 *
	 * @return iterable<array{string, string}> Local time and account timezone.
	 */
	public static function gaps(): iterable {
		yield ['2026-03-08T02:30', 'America/New_York'];
		yield ['2026-03-29T02:30', 'Europe/Berlin'];
		yield ['2026-09-06T00:00', 'America/Santiago'];
	}

	/**
	 * Do not silently move an explicitly requested nonexistent time to another hour.
	 *
	 * @param string $input Nonexistent clock time.
	 * @param string $zone Account timezone.
	 * @return void
	 */
	#[DataProvider('gaps')]
	public function testLocalGapIsRefused(string $input, string $zone): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('does not exist');
		CardDueDate::forDeck($input, new DateTimeZone($zone));
	}

	/**
	 * Canonical plans distinguish a changed hour but ignore equivalent offset spellings.
	 *
	 * @return void
	 */
	public function testPlanAndReadPresentation(): void {
		$zone = new DateTimeZone('America/Sao_Paulo');
		self::assertSame(CardDueDateView::planValue('2026-10-01T15:30', $zone), CardDueDateView::planValue('2026-10-01T20:30+02:00', $zone));
		self::assertNotSame(CardDueDateView::planValue('2026-10-01T15:30', $zone), CardDueDateView::planValue('2026-10-01T16:30', $zone));
		self::assertSame('2026-10-01', CardDueDateView::formatPlan('2026-10-01', $zone));
		self::assertSame('2026-10-01 15:30 (America/Sao\_Paulo, UTC-03:00)', CardDueDateView::formatPlan('2026-10-01T15:30', $zone));
		self::assertSame('2026-10-01 15:30:19 (America/Sao\_Paulo, UTC-03:00)', CardDueDateView::formatPlan('2026-10-01T18:30:19Z', $zone));
		self::assertSame('2026-10-01 15:30 (Asia/Tokyo, UTC+09:00)', CardDueDateView::formatPlan('2026-10-01T15:30', new DateTimeZone('Asia/Tokyo')));
		self::assertSame('2026-11-01 00:00 (America/Havana, UTC-04:00)', CardDueDateView::formatPlan('2026-11-01T00:00', new DateTimeZone('America/Havana')));
		self::assertSame('2026-11-01 00:00 (America/Havana, UTC-04:00)', CardDueDateView::formatPlan('2026-11-01', new DateTimeZone('America/Havana')));
		self::assertSame('2026-09-06 01:00 (America/Santiago, UTC-03:00)', CardDueDateView::formatPlan('2026-09-06', new DateTimeZone('America/Santiago')));
		self::assertSame('2026-10-01 07:00 (America/Sao\_Paulo, UTC-03:00)', CardDueDateView::formatPlan('2026-10-01T10:00-00:00', $zone));
		self::assertSame(CardDueDateView::planValue('2026-10-01T10:00Z', $zone), CardDueDateView::planValue('2026-10-01T10:00-00:00', $zone));
		self::assertNull(CardDueDateView::dueAt(null, $zone));
		$native = new \DateTime('2026-10-01T18:30:19Z');
		self::assertSame('2026-10-01T15:30:19-03:00', CardDueDateView::dueAt($native, $zone));
		self::assertSame('+00:00', $native->format('P'));
		self::assertTrue(CardDueDate::same(null, null, $zone));
		self::assertFalse(CardDueDate::same(null, '2026-10-01', $zone));
	}

	/**
	 * Board blueprints use the same account timezone and reject gaps before creating any board.
	 *
	 * @return void
	 */
	public function testBlueprintUsesAccountTimezone(): void {
		$args = ['title' => 'Timed board', 'stacks' => [['title' => 'Todo', 'cards' => [['title' => 'Timed', 'duedate' => '2026-10-01T15:30']]]]];
		self::assertSame('2026-10-01T15:30', BoardBlueprint::parse($args, 'alice', new DateTimeZone('Asia/Tokyo'))['stacks'][0]['cards'][0]['duedate']);
		$args['stacks'][0]['cards'][0]['duedate'] = '2026-09-06T00:00';
		$this->expectException(\OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException::class);
		BoardBlueprint::parse($args, 'alice', new DateTimeZone('America/Santiago'));
	}
}
