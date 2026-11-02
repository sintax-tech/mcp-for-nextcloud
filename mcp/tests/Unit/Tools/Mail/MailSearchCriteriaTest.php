<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail;

use DateTimeZone;
use OCA\Mcp\Modules\Mail\Reading\MailCursor;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;
use OCA\Mcp\Modules\Mail\Reading\MailSearchCriteria;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\TestCase;

/**
 * Filters become the Mail search query field by field, dates follow the user's time zone, and cursors are bound to
 * the search they came from.
 */
final class MailSearchCriteriaTest extends TestCase {
	/**
	 * Every filter reaches the search query; deleted messages are excluded outside the trash only.
	 *
	 * @return void
	 */
	public function testEveryFilterReachesTheQuery(): void {
		$criteria = MailSearchCriteria::fromArguments(['from' => [' carol '], 'to' => ['dan'], 'cc' => ['eve'], 'subject' => 'venue',
			'body' => 'booked', 'since' => '2026-10-01', 'until' => '2026-10-01', 'unread' => true, 'flagged' => false, 'has_attachments' => true],
			new DateTimeZone('America/Sao_Paulo'));
		$app = new FakeMailMessageApp();
		/** @var FakeSearchQuery $query */
		$query = $criteria->toQuery($app, 'alice', false);
		$this->assertSame(['carol'], $query->from);
		$this->assertSame(['dan'], $query->to);
		$this->assertSame(['eve'], $query->cc);
		$this->assertSame(['venue'], $query->subjects);
		$this->assertSame(['booked'], $query->bodies);
		$this->assertSame('1790823600', $query->start, '2026-10-01 00:00 in São Paulo');
		$this->assertSame('1790909999', $query->end, '2026-10-01 23:59:59 in São Paulo');
		$this->assertTrue($query->hasAttachments);
		$this->assertSame([['flag' => 'SEEN', 'set' => false], ['flag' => 'FLAGGED', 'set' => false], ['flag' => 'DELETED', 'set' => false]], $query->flags);
		$this->assertNotContains(['flag' => 'DELETED', 'set' => false], $criteria->toQuery($app, 'alice', true)->flags);
		$this->assertTrue($criteria->searchesBody());
		$this->assertFalse($query->threaded, 'every message of a thread is listed, not only its newest');
		$fiveZero = new class extends \ArrayObject {
			/** @param string $value fragment @return void */
			public function addFrom(string $value): void {
			}

			/** @param \ArrayObject<string, mixed> $flag condition @return void */
			public function addFlag(\ArrayObject $flag): void {
			}
		};
		$app50 = new FakeMailMessageApp();
		$app50->searchQuery = $fiveZero;
		$this->assertSame($fiveZero, MailSearchCriteria::fromArguments(['from' => ['x']], new DateTimeZone('UTC'))->toQuery($app50, 'alice', false));

		/** @var FakeSearchQuery $boundary */
		$boundary = $criteria->toQuery($app, 'alice', false, 1790850000, 1790850000, null);
		$this->assertSame(['1790850000', '1790850000', null], [$boundary->start, $boundary->end, $boundary->cursor]);
		$this->assertSame(123, $criteria->toQuery($app, 'alice', false, null, null, 123)->cursor);
	}

	/**
	 * A date with a time and an offset is taken as given.
	 *
	 * @return void
	 */
	public function testADateWithOffsetIsTakenAsGiven(): void {
		$criteria = MailSearchCriteria::fromArguments(['since' => '2026-10-01T12:00:00Z'], new DateTimeZone('Asia/Tokyo'));
		$this->assertSame(1790856000, $criteria->since);
	}

	/**
	 * Invalid dates, empty texts and reversed ranges are refused.
	 *
	 * @return void
	 */
	public function testInvalidValuesAreRefused(): void {
		$zone = new DateTimeZone('UTC');
		foreach ([['since' => '2026-02-30'], ['until' => 'tomorrow'], ['since' => '2026-10-01T10:00']] as $arguments) {
			$this->assertRefused($arguments, $zone);
		}
		$this->assertRefused(['subject' => " \u{0007} "], $zone, MailMessages::emptyFilter());
		$this->assertRefused(['since' => '2026-10-02', 'until' => '2026-10-01'], $zone, MailMessages::invalidDateRange());
	}

	/**
	 * A cursor round-trips for its search and is refused for any other one or when forged.
	 *
	 * @return void
	 */
	public function testACursorBelongsToItsSearch(): void {
		$cursor = (new MailCursor(1790000000, 'abc'))->encode();
		$this->assertSame(1790000000, MailCursor::decode($cursor, 'abc')->before);
		foreach ([[$cursor, 'xyz', MailMessages::foreignCursor()], ['%%%', 'abc', MailMessages::invalidCursor()],
			[rtrim(strtr(base64_encode('{"v":2,"b":1,"f":"abc"}'), '+/', '-_'), '='), 'abc', MailMessages::invalidCursor()],
			[str_repeat('A', MailCursor::MAX_LENGTH + 1), 'abc', MailMessages::invalidCursor()]] as [$value, $fingerprint, $message]) {
			try {
				MailCursor::decode($value, $fingerprint);
				$this->fail('accepted ' . $value);
			} catch (ToolFailure $e) {
				$this->assertSame($message, $e->getMessage());
			}
		}
	}

	/**
	 * Asserts that the arguments are refused.
	 *
	 * @param array<string, mixed> $arguments filter arguments
	 * @param DateTimeZone $zone user time zone
	 * @param string|null $message expected message, any when null
	 * @return void
	 */
	private function assertRefused(array $arguments, DateTimeZone $zone, ?string $message = null): void {
		try {
			MailSearchCriteria::fromArguments($arguments, $zone);
			$this->fail('accepted ' . json_encode($arguments));
		} catch (ToolFailure $e) {
			if ($message !== null) {
				$this->assertSame($message, $e->getMessage());
			}
		}
	}
}
