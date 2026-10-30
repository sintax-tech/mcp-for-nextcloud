<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use OCA\Mcp\Tools\Mail\MailMessagePager;
use OCA\Mcp\Tools\ToolFailure;
use PHPUnit\Framework\TestCase;

/**
 * The pager over sources that behave like the Mail cache query: ordered by date only, same dates in an arbitrary
 * order, cut at the limit. Whatever the order of equal dates, every message comes exactly once over the pages.
 */
final class MailMessagePagerTest extends TestCase {
	/**
	 * Every split of equal dates across folders and pages yields each message once, for several page sizes and
	 * several orders of equal dates.
	 *
	 * @return void
	 */
	public function testEveryMessageComesExactlyOnce(): void {
		$folders = [
			[[1, 500], [2, 400], [3, 400], [4, 400], [5, 300], [6, 100]],
			[[11, 400], [12, 400], [13, 300], [14, 300], [15, 300], [16, 200]],
			[[21, 600], [22, 100], [23, 100]],
		];
		foreach ([1, 2, 3, 5, 50] as $limit) {
			foreach ([false, true] as $reverse) {
				$this->assertSame([1, 2, 3, 4, 5, 6, 11, 12, 13, 14, 15, 16, 21, 22, 23], $this->all($folders, $limit, $reverse), "limit $limit");
			}
		}
	}

	/**
	 * A page ends after the last message of its date: the ties of the boundary date all come on the same page.
	 *
	 * @return void
	 */
	public function testAPageNeverEndsInsideADate(): void {
		$rows = [[1, 300], [2, 200], [3, 200], [4, 200], [5, 100]];
		$page = (new MailMessagePager())->page([$this->source($rows, false)], $this->loader($rows), 2, null);
		$this->assertSame([1, 4, 3, 2], array_map(static fn (object $m): int => $m->getId(), $page['messages']));
		$this->assertTrue($page['hasMore']);
		$this->assertSame(200, $page['nextBefore']);
	}

	/**
	 * A last page has no cursor, even when its last date is shared.
	 *
	 * @return void
	 */
	public function testTheLastPageHasNoCursor(): void {
		$rows = [[1, 300], [2, 200], [3, 200]];
		$page = (new MailMessagePager())->page([$this->source($rows, false)], $this->loader($rows), 2, null);
		$this->assertSame([1, 3, 2], array_map(static fn (object $m): int => $m->getId(), $page['messages']));
		$this->assertFalse($page['hasMore']);
		$this->assertNull($page['nextBefore']);
	}

	/**
	 * More messages on one date than a page may add are refused instead of cut.
	 *
	 * @return void
	 */
	public function testTooManyMessagesOnOneDateAreRefused(): void {
		$rows = array_map(static fn (int $id): array => [$id, 100], range(1, MailMessagePager::MAX_SAME_DATE + 1));
		$this->expectException(ToolFailure::class);
		(new MailMessagePager())->page([$this->source($rows, false)], $this->loader($rows), 10, null);
	}

	/**
	 * All ids over all pages.
	 *
	 * @param list<list<array{int, int}>> $folders [id, date] rows per folder
	 * @param int $limit page size
	 * @param bool $reverse order of equal dates in the sources
	 * @return list<int> sorted ids
	 */
	private function all(array $folders, int $limit, bool $reverse): array {
		$sources = array_map(fn (array $rows): callable => $this->source($rows, $reverse), $folders);
		$seen = [];
		$before = null;
		$guard = 0;
		do {
			$page = (new MailMessagePager())->page($sources, $this->loader(array_merge(...$folders)), $limit, $before);
			array_push($seen, ...array_map(static fn (object $m): int => $m->getId(), $page['messages']));
			$before = $page['nextBefore'];
		} while ($before !== null && ++$guard < 50);
		$this->assertSame(count($seen), count(array_unique($seen)), 'no message repeated');
		sort($seen);
		return $seen;
	}

	/**
	 * A source like the Mail cache query.
	 *
	 * @param list<array{int, int}> $rows [id, date]
	 * @param bool $reverse whether equal dates come in descending id order instead of ascending
	 * @return callable(int, ?int, ?int, ?int): list<int> the source
	 */
	private function source(array $rows, bool $reverse): callable {
		return static function (int $limit, ?int $before, ?int $since, ?int $until) use ($rows, $reverse): array {
			$match = array_values(array_filter($rows, static fn (array $r): bool => ($before === null || $r[1] < $before)
				&& ($since === null || $r[1] >= $since) && ($until === null || $r[1] <= $until)));
			usort($match, static fn (array $a, array $b): int => [$b[1], $reverse ? $b[0] : $a[0]] <=> [$a[1], $reverse ? $a[0] : $b[0]]);
			return array_map(static fn (array $r): int => $r[0], array_slice($match, 0, $limit));
		};
	}

	/**
	 * A loader of message doubles with the dates of the fixture rows.
	 *
	 * @param list<array{int, int}> $rows [id, date] of every message
	 * @return callable(list<int>): list<object> the loader
	 */
	private function loader(array $rows): callable {
		$dates = array_column($rows, 1, 0);
		return static fn (array $ids): array => array_map(static fn (int $id): object => new class ($id, $dates[$id]) {
			/**
			 * @param int $id message id
			 * @param int $sentAt send date
			 * @return void
			 */
			public function __construct(private int $id, private int $sentAt) {
			}

			/** @return int message id */
			public function getId(): int {
				return $this->id;
			}

			/** @return int send date */
			public function getSentAt(): int {
				return $this->sentAt;
			}
		}, $ids);
	}
}
