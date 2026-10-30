<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Columns;

use OCA\Mcp\Tests\Unit\Tools\Tables\Support\FakeTablesApp;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesScene;
use OCA\Mcp\Tools\Tables\Columns\ColumnLookup;
use OCA\Mcp\Tools\Tables\TablesMessages;
use OCA\Mcp\Tools\ToolFailure;
use PHPUnit\Framework\TestCase;

/**
 * The columns of a table need read access to the table, the columns of a view access to the view; both are checked for
 * the user before any column is read, and a table or view out of reach reads exactly like a missing one.
 */
final class ColumnLookupTest extends TestCase {
	/**
	 * The owner and a read-only share read every column of the table; a view share, an application member and a
	 * stranger read none, and the column service is never asked.
	 *
	 * @return void
	 */
	public function testTheColumnsOfATableNeedTheTable(): void {
		$app = TablesScene::app();
		foreach (['alice', 'carol'] as $uid) {
			$this->assertSame([11, 12, 13], $this->ids((new ColumnLookup($app))->forTable($uid, 1)), $uid);
		}
		foreach (['bob', 'erin', 'dave'] as $uid) {
			$this->assertSame(TablesMessages::notFound(), $this->failure(fn () => (new ColumnLookup($app))->forTable($uid, 1)), $uid);
		}
		$this->assertSame(TablesMessages::notFound(), $this->failure(fn () => (new ColumnLookup($app))->forTable('alice', 404)));
		$this->assertSame(['alice', 'carol'], array_column($app->callsOf('ColumnService', 'findAllByTable'), 1));
	}

	/**
	 * Through a view, only the columns it shows, with its settings: the hidden column never comes back, for any user.
	 *
	 * @return void
	 */
	public function testTheColumnsOfAViewAreOnlyTheOnesItShows(): void {
		$app = TablesScene::app();
		// The owner first: the table's own columns are all readable to them, so only the view keeps the third one out.
		$this->assertSame([11, 12], $this->ids((new ColumnLookup($app))->forView('alice', 21)));
		$this->assertSame([11, 12], $this->ids((new ColumnLookup($app))->forView('bob', 21)));
		$this->assertSame([11], $this->ids((new ColumnLookup($app))->forView('erin', 22)));
		$this->assertSame([], $app->callsOf('Column#13', 'jsonSerialize'));
		$this->assertSame([[21, true, 'alice'], [21, true, 'bob'], [22, true, 'erin']], $app->callsOf('ViewService', 'find'));
	}

	/**
	 * A view the user cannot open, another user's view and a missing view read the same, and nothing of them is read.
	 *
	 * @return void
	 */
	public function testAViewOutOfReachReadsLikeAMissingOne(): void {
		$app = TablesScene::app();
		$messages = [
			$this->failure(fn () => (new ColumnLookup($app))->forView('bob', 22)),
			$this->failure(fn () => (new ColumnLookup($app))->forView('carol', 21)),
			$this->failure(fn () => (new ColumnLookup($app))->forView('dave', 999)),
		];
		$this->assertSame(array_fill(0, 3, TablesMessages::notFound()), $messages);
		$this->assertSame([], $app->callsOf('ColumnService', 'findAllByView'));
	}

	/**
	 * A view the service returns but canAccessView() refuses is refused too.
	 *
	 * @return void
	 */
	public function testAViewTheCheckRefusesIsRefused(): void {
		$app = new class extends FakeTablesApp {
			protected function resolve(string $class, string $userId): object {
				$service = parent::resolve($class, $userId);
				return $class !== 'OCA\\Tables\\Service\\PermissionsService' ? $service : new \OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesDouble('PermissionsService', [
					'canAccessView' => static fn (): bool => false,
				], $this->journal);
			}
		};
		$app->withTable(1, 'alice', 'Budget')->withView(21, 1, 'Approved', []);
		$this->assertSame(TablesMessages::notFound(), $this->failure(fn () => (new ColumnLookup($app))->forView('alice', 21)));
		$this->assertSame([], $app->callsOf('ColumnService', 'findAllByView'));
	}

	/**
	 * The ids of the columns.
	 *
	 * @param list<object> $columns columns
	 * @return list<int> their ids
	 */
	private function ids(array $columns): array {
		return array_map(static fn (object $column): int => $column->jsonSerialize()['id'], $columns);
	}

	/**
	 * The message of the failure of a call.
	 *
	 * @param callable(): mixed $call the call
	 * @return string the message
	 */
	private function failure(callable $call): string {
		try {
			$call();
		} catch (ToolFailure $e) {
			return $e->getMessage();
		}
		$this->fail('expected a failure');
	}
}
