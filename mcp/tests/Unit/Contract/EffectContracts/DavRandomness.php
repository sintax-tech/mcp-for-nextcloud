<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Tests\Unit\Tools\Calendar\FakeCalendarStore;
use PHPUnit\Framework\TestCase;

/**
 * The random and wall-clock values a calendar or task write stores, read from the bytes the store really holds:
 * the UID the code draws for a new object (and the object URI made of it), a DTSTAMP Sabre stamps with the real clock,
 * and the ETag of an object whose text holds either. They become placeholders in the transcript only.
 *
 * Before that, {@see self::assertShapes()} pins what the placeholders hide: each drawn UID has the drawn shape, two
 * objects never share one, and every DTSTAMP is a fixture value, the frozen clock of the module, or a real-clock stamp
 * taken while the test ran (a one-second margin), never another time.
 */
final class DavRandomness {
	/** A version-4 UUID, as CreateEvent draws an event UID. */
	public const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
	/** 128 random bits in hex, as TaskData draws a task UID. */
	public const HEX_128 = '/^[0-9a-f]{32}$/';
	/** Margin of the real-clock window, in seconds. */
	private const MARGIN = 1;

	private int $start;

	/**
	 * @param FakeCalendarStore $store the store the contract writes to
	 * @param list<string> $fixtureUids UIDs the fixture created (not drawn by the code)
	 * @param list<string> $fixedStamps DTSTAMP values that are deterministic: the fixture's and the module clock's
	 * @param string $uidPattern shape of a drawn UID ({@see self::UUID_V4} or {@see self::HEX_128})
	 */
	public function __construct(private FakeCalendarStore $store, private array $fixtureUids, private array $fixedStamps,
		private string $uidPattern) {
		$this->start = time();
	}

	/** @return array<string, string> original value => placeholder, for {@see \OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow::normalizing()} */
	public function literals(): array {
		$map = [];
		$uids = 0;
		$etags = 0;
		foreach ($this->objects() as $row) {
			$random = false;
			foreach ($this->uids($row['data']) as $uid) {
				$map[$uid] ??= '<drawn-uid#' . ++$uids . '>';
				$random = true;
			}
			foreach ($this->stamps($row['data']) as $stamp) {
				if (!in_array($stamp, $this->fixedStamps, true)) {
					$map[$stamp] = '<real-clock DTSTAMP>';
					$random = true;
				}
			}
			if ($random) {
				$map[$row['etag']] ??= '<etag-of-drawn-object#' . ++$etags . '>';
				$map[trim($row['etag'], '"')] ??= '<etag-of-drawn-object#' . $etags . '>';
			}
		}
		return $map;
	}

	/**
	 * @param TestCase $test the running test
	 * @return void
	 */
	public function assertShapes(TestCase $test): void {
		$end = time();
		$drawn = [];
		foreach ($this->objects() as $row) {
			foreach ($this->uids($row['data']) as $uid) {
				$test->assertMatchesRegularExpression($this->uidPattern, $uid, 'a drawn UID must have the drawn shape');
				$drawn[] = $uid;
			}
			foreach ($this->stamps($row['data']) as $stamp) {
				if (in_array($stamp, $this->fixedStamps, true)) {
					continue;
				}
				$at = \DateTimeImmutable::createFromFormat('!Ymd\THis\Z', $stamp, new \DateTimeZone('UTC'));
				$test->assertNotFalse($at, 'unreadable DTSTAMP ' . $stamp);
				$test->assertGreaterThanOrEqual($this->start - self::MARGIN, $at->getTimestamp(), 'DTSTAMP ' . $stamp . ' is older than the test');
				$test->assertLessThanOrEqual($end + self::MARGIN, $at->getTimestamp(), 'DTSTAMP ' . $stamp . ' is later than the test');
			}
		}
		$test->assertSame(count($drawn), count(array_unique($drawn)), 'two objects share a drawn UID');
	}

	/** @return list<array{data:string, etag:string}> every object of the store, deleted ones included, in storage order */
	private function objects(): array {
		$out = [];
		foreach ($this->store->objects as $rows) {
			foreach ($rows as $row) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * @param string $data iCalendar text
	 * @return list<string> the UIDs in it that the fixture did not create
	 */
	private function uids(string $data): array {
		preg_match_all('/^UID:(.+?)\r?$/m', $data, $matches);
		return array_values(array_diff($matches[1], $this->fixtureUids));
	}

	/**
	 * @param string $data iCalendar text
	 * @return list<string> every DTSTAMP value in it
	 */
	private function stamps(string $data): array {
		preg_match_all('/^DTSTAMP:(\d{8}T\d{6}Z)\r?$/m', $data, $matches);
		return $matches[1];
	}
}
