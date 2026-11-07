<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

use OCA\Mcp\Tests\Integration\Tables\Rows\RowsReadRequest;
use PHPUnit\Framework\TestCase;

/** The oracle comparison must share one request and never canonicalize the observed row order. */
final class RowsReadRequestTest extends TestCase {
	/** @return void All cursor pages precede exactly one native read in the same request. */
	public function testComparisonKeepsTheActualNativeOrderAndOneRequest(): void {
		$events = [];
		$ids = [[5, 1], [6, 2], [4]];
		$request = new RowsReadRequest(
			static function (array $args) use (&$events, $ids): array {
				$offset = (int)($args['cursor'] ?? 0);
				$events[] = ['page', $args['view_id'], $offset];
				return ['content' => [['text' => json_encode(['rows' => $ids[$offset], 'nextCursor' => $offset < 2 ? (string)($offset + 1) : null], JSON_THROW_ON_ERROR)]]];
			},
			static function (array $target) use (&$events): array {
				$events[] = ['native', $target['view_id']];
				return ['rows' => [5, 1, 6, 2, 4]];
			},
		);
		$result = $request->compare(['arguments' => ['view_id' => 3, 'limit' => 2]]);
		$this->assertSame([['page', 3, 0], ['page', 3, 1], ['page', 3, 2], ['native', 3]], $events);
		$actual = array_merge(...array_map(static fn (array $page): array => json_decode($page['content'][0]['text'], true)['rows'], $result['pages']));
		$this->assertSame($result['native']['rows'], $actual);
	}
	/** @return void A differing native order stays different, so the integration assertion cannot be weakened. */
	public function testComparisonDoesNotHideAnOrderMismatch(): void {
		$request = new RowsReadRequest(
			static fn (): array => ['content' => [['text' => '{"rows":[5,1,6,2,4],"nextCursor":null}']]],
			static fn (): array => ['rows' => [5, 6, 1, 2, 4]],
		);
		$result = $request->compare(['arguments' => ['view_id' => 3]]);
		$this->assertSame([5, 6, 1, 2, 4], $result['native']['rows']);
		$this->assertNotSame($result['native']['rows'], json_decode($result['pages'][0]['content'][0]['text'], true)['rows']);
	}
}
