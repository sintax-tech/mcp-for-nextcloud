<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Tables\Rows;

use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Tables\TablesCellRows;
use OCA\Mcp\Modules\Tables\Reading\RowCellText;
use OCA\Mcp\Modules\Tables\Presentation\RowsMessages;
use OCA\Mcp\Modules\Tables\Presentation\TablesMessages;
use PHPUnit\Framework\Attributes\Group;

/**
 * tables_list_rows against the real Tables app of a Tables cell (ci/tables/seed-rows.php), through the real registry:
 * - every user reads, page by page, exactly the rows, visible cells and values RowService itself returns (a long text
 *   whole, a text past the cell budget cut and flagged with its native length), and nothing changes in any table of the
 *   Tables app;
 * - the native row permission decides: a create-only share and a stranger read like a missing target;
 * - the session must be the caller's: another user's session or none is refused;
 * - through the view, the saved filter on the hidden column applies and the hidden column never comes back; a search the
 *   hidden column alone would match finds nothing; the user's filter never widens the view;
 * - selection labels find option ids as whole ids (Blue is 10, never 1), a missing cell follows its default as natively;
 * - ties page without loss or repetition, and an explicit id DESC is kept;
 * - a view whose saved filter names another table's column is refused;
 * - a change of the default of the hidden column the saved filter names, alone, refuses the cursor of the view.
 */
#[Group('tables')]
final class McpTablesRowsTest extends IntegrationTestCase {
	use TablesCellRows;

	/**
	 * Runs only in a Tables cell.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		if (getenv('MCP_IT_WITH_TABLES') !== '1') {
			$this->markTestSkipped('Tables cell only (MCP_IT_WITH_TABLES=1)');
		}
	}

	/**
	 * Every user and target, two rows per page, against RowService in the same actor request, and the database unchanged.
	 *
	 * @return void
	 */
	public function testEachUserReadsWhatRowServiceReturns(): void {
		$ids = RowsCellScene::ids();
		$events = $ids['tables']['events'];
		$open = $ids['views']['open'];
		$before = $this->tablesAppRows();
		$cases = [
			[RowsCellScene::OWNER, ['table_id' => $events]],
			[RowsCellScene::OWNER, ['view_id' => $open]],
			[RowsCellScene::VIEWER, ['view_id' => $open]],
			[RowsCellScene::READER, ['table_id' => $events]],
			[RowsCellScene::MEMBER, ['view_id' => $open]],
		];
		$kinds = ['same' => 0, 'whole' => 0, 'cut' => 0];
		foreach ($cases as [$uid, $target]) {
			$comparison = RowsActor::run($uid, 'compare', ['arguments' => $target + ['limit' => 2]]);
			$mcp = self::rows($comparison);
			$native = $comparison['native'];
			$this->assertArrayHasKey('rows', $native, "$uid native");
			$this->assertSame(array_column($native['rows'], 'id'), array_column($mcp, 'id'), "$uid " . json_encode($target) . ': rows and order');
			foreach ($native['rows'] as $i => $row) {
				$this->assertSame(array_column($row['data'], 'columnId'), array_column($mcp[$i]['cells'], 'columnId'), "$uid row {$row['id']}: cells");
				foreach ($row['data'] as $j => $cell) {
					$kinds[self::assertSameValue($cell['value'], $mcp[$i]['cells'][$j], "$uid row {$row['id']} column {$cell['columnId']}")]++;
				}
			}
		}
		$this->assertSame([2, 2], [$kinds['whole'], $kinds['cut']], 'the long notes, whole and cut, for the owner and the reader of the table');
		foreach ([[RowsCellScene::CREATOR, ['table_id' => $events]], [RowsCellScene::STRANGER, ['table_id' => $events]], [RowsCellScene::STRANGER, ['view_id' => $open]],
			[RowsCellScene::VIEWER, ['table_id' => $events]]] as [$uid, $target]) {
			$page = RowsActor::run($uid, 'pages', ['arguments' => $target])['pages'][0];
			$this->assertTrue($page['isError'] ?? false, "$uid " . json_encode($target));
			$this->assertSame(TablesMessages::notFound(), $page['content'][0]['text']);
			$this->assertArrayHasKey('error', RowsActor::run($uid, 'native', $target), "$uid native refuses too");
		}
		$this->assertSame($before, $this->tablesAppRows(), 'a table of the Tables app changed');
	}

	/**
	 * Another user's session or no session user: refused with the fixed message, for the owner of the rows too.
	 *
	 * @return void
	 */
	public function testTheSessionMustBeTheCallers(): void {
		$open = RowsCellScene::ids()['views']['open'];
		foreach ([RowsCellScene::OWNER, ''] as $session) {
			$page = RowsActor::run(RowsCellScene::VIEWER, 'pages', ['session' => $session, 'arguments' => ['view_id' => $open]])['pages'][0];
			$this->assertTrue($page['isError'] ?? false, "session '$session'");
			$this->assertSame(RowsMessages::sessionMismatch(), $page['content'][0]['text']);
		}
	}

	/**
	 * The view's hidden filter applies and its column never comes back; a search only the hidden column would match finds
	 * nothing for the viewer, though the owner finds that row in the table; the viewer's filter never widens the view.
	 *
	 * @return void
	 */
	public function testTheViewStaysRestricted(): void {
		$ids = RowsCellScene::ids();
		['open' => $open] = $ids['views'];
		$rows = $ids['rows'];
		$inView = [$rows[0], $rows[1], $rows[3], $rows[4], $rows[5]];
		$viewer = self::rows(RowsActor::run(RowsCellScene::VIEWER, 'pages', ['arguments' => ['view_id' => $open, 'limit' => 100]]));
		$this->assertEqualsCanonicalizing($inView, array_column($viewer, 'id'));
		$this->assertStringNotContainsString('needle', json_encode($viewer, JSON_THROW_ON_ERROR));
		$this->assertNotContains($ids['columns']['secret'], array_merge(...array_map(static fn (array $r): array => array_column($r['cells'], 'columnId'), $viewer)));

		$this->assertSame([], self::rows(RowsActor::run(RowsCellScene::VIEWER, 'pages', ['arguments' => ['view_id' => $open, 'search' => 'needle']])));
		$this->assertSame([$rows[4]], array_column(self::rows(RowsActor::run(RowsCellScene::OWNER, 'pages', ['arguments' => ['table_id' => $ids['tables']['events'], 'search' => 'needle']])), 'id'));

		$widening = self::rows(RowsActor::run(RowsCellScene::VIEWER, 'pages', ['arguments' => ['view_id' => $open, 'limit' => 100,
			'filter' => [[['column_id' => $ids['columns']['amount'], 'operator' => 'is-greater-than', 'value' => 0]]]]]));
		$this->assertSame([], array_values(array_diff(array_column($widening, 'id'), $inView)), 'a user filter never widens the view');
		$this->assertNotContains($rows[2], array_column($widening, 'id'));

		$hidden = RowsActor::run(RowsCellScene::VIEWER, 'pages', ['arguments' => ['view_id' => $open,
			'filter' => [[['column_id' => $ids['columns']['secret'], 'operator' => 'contains', 'value' => 'ok']]]]])['pages'][0];
		$this->assertSame(RowsMessages::invalidQuery(), $hidden['content'][0]['text'] ?? null);
	}

	/**
	 * Blue finds the rows tagged 10, never the one tagged 1 only; Status 1 finds the rows set to 1 and the row without a
	 * Status, whose default is 1 (the native includeDefault), never the one set to 10.
	 *
	 * @return void
	 */
	public function testSelectionsAndDefaults(): void {
		$ids = RowsCellScene::ids();
		$rows = $ids['rows'];
		$events = $ids['tables']['events'];
		$blue = array_column(self::rows(RowsActor::run(RowsCellScene::OWNER, 'pages', ['arguments' => ['table_id' => $events, 'search' => 'Blue', 'limit' => 100]])), 'id');
		$this->assertEqualsCanonicalizing([$rows[1], $rows[2], $rows[5]], $blue, 'tags [10], [1,10] and [10]');
		$status = array_column(self::rows(RowsActor::run(RowsCellScene::OWNER, 'pages', ['arguments' => ['table_id' => $events, 'limit' => 100,
			'filter' => [[['column_id' => $ids['columns']['status'], 'operator' => 'is-equal', 'value' => 1]]]]])), 'id');
		$this->assertContains($rows[0], $status);
		$this->assertContains($rows[5], $status);
		$this->assertContains($rows[3], $status, 'the row without a Status follows its default');
		$this->assertNotContains($rows[1], $status, 'option 10 is not option 1');
		$arvore = array_column(self::rows(RowsActor::run(RowsCellScene::OWNER, 'pages', ['arguments' => ['table_id' => $events, 'search' => 'ÁRVORE', 'limit' => 100]])), 'id');
		$this->assertSame([$rows[1]], $arvore, 'a composed label found by a decomposed, upper-case term');
	}

	/**
	 * One row per page over ties on Amount: every row once, in order; an explicit id DESC is the order.
	 *
	 * @return void
	 */
	public function testTiesAndIdDescending(): void {
		$ids = RowsCellScene::ids();
		$events = $ids['tables']['events'];
		$paged = array_column(self::rows(RowsActor::run(RowsCellScene::OWNER, 'pages', ['arguments' => ['table_id' => $events, 'limit' => 1,
			'sort' => [['column_id' => $ids['columns']['amount'], 'mode' => 'ASC']]]])), 'id');
		$this->assertCount(6, $paged);
		$this->assertSame($paged, array_values(array_unique($paged)), 'no row twice');
		$whole = array_column(self::rows(RowsActor::run(RowsCellScene::OWNER, 'pages', ['arguments' => ['table_id' => $events, 'limit' => 100,
			'sort' => [['column_id' => $ids['columns']['amount'], 'mode' => 'ASC']]]])), 'id');
		$this->assertSame($whole, $paged, 'pages of one row give the order of one page');
		$descending = array_column(self::rows(RowsActor::run(RowsCellScene::OWNER, 'pages', ['arguments' => ['table_id' => $events, 'limit' => 2,
			'sort' => [['column_id' => -1, 'mode' => 'DESC']]]])), 'id');
		$expected = $ids['rows'];
		rsort($expected);
		$this->assertSame($expected, $descending);
	}

	/**
	 * A view whose saved filter names another table's column is refused before the engine.
	 *
	 * @return void
	 */
	public function testAForeignSavedRuleIsRefused(): void {
		$page = RowsActor::run(RowsCellScene::VIEWER, 'pages', ['arguments' => ['view_id' => RowsCellScene::ids()['views']['broken']]])['pages'][0];
		$this->assertTrue($page['isError'] ?? false);
		$this->assertSame(RowsMessages::savedRulesUnusable(), $page['content'][0]['text']);
	}

	/**
	 * The viewer's cursor of the view stays valid while nothing changes, and is refused once only the text default of the
	 * hidden column its saved filter names changes; the default is restored after.
	 *
	 * @return void
	 */
	public function testAHiddenDefaultChangeRefusesTheCursor(): void {
		$ids = RowsCellScene::ids();
		$arguments = ['view_id' => $ids['views']['open'], 'limit' => 2];
		$first = json_decode(RowsActor::run(RowsCellScene::VIEWER, 'pages', ['arguments' => $arguments, 'maxPages' => 1])['pages'][0]['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
		$next = $arguments + ['cursor' => $first['nextCursor']];
		$this->assertFalse(RowsActor::run(RowsCellScene::VIEWER, 'pages', ['arguments' => $next, 'maxPages' => 1])['pages'][0]['isError'] ?? false, 'the stable control');
		$previous = RowsActor::run(RowsCellScene::OWNER, 'column-default', ['column' => $ids['columns']['secret'], 'textDefault' => 'ok by default'])['previous'];
		try {
			$stale = RowsActor::run(RowsCellScene::VIEWER, 'pages', ['arguments' => $next, 'maxPages' => 1])['pages'][0];
			$this->assertTrue($stale['isError'] ?? false);
			$this->assertSame(RowsMessages::staleCursor(), $stale['content'][0]['text']);
		} finally {
			RowsActor::run(RowsCellScene::OWNER, 'column-default', ['column' => $ids['columns']['secret'], 'textDefault' => $previous]);
		}
	}

	/**
	 * A cell value against the native one: the same, or, for a text past the cell budget, its start, flagged with the
	 * native length.
	 *
	 * @param mixed $native the value RowService returns
	 * @param array<string, mixed> $cell the cell tables_list_rows returns
	 * @param string $label what is compared
	 * @return string `cut` for a cut text, `whole` for another text past 2000 characters, `same` otherwise
	 */
	private static function assertSameValue(mixed $native, array $cell, string $label): string {
		if (!is_string($native) || mb_strlen($native) <= RowCellText::MAX_CELL_CHARS) {
			self::assertSame($native, $cell['value'], "$label: value");
			self::assertArrayNotHasKey('truncated', $cell, $label);
			return is_string($native) && mb_strlen($native) > 2000 ? 'whole' : 'same';
		}
		self::assertSame([mb_substr($native, 0, RowCellText::MAX_CELL_CHARS), true, mb_strlen($native)], [$cell['value'], $cell['truncated'] ?? null, $cell['totalChars'] ?? null], "$label: cut");
		return 'cut';
	}

	/**
	 * The rows of every page of an actor's answer.
	 *
	 * @param array{pages:list<array<string, mixed>>} $out the actor's answer
	 * @return list<array<string, mixed>> the rows
	 */
	private static function rows(array $out): array {
		$rows = [];
		foreach ($out['pages'] as $page) {
			self::assertFalse($page['isError'] ?? false, json_encode($page, JSON_THROW_ON_ERROR));
			self::assertArrayNotHasKey('refused', $page);
			$rows = [...$rows, ...json_decode($page['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR)['rows']];
		}
		return $rows;
	}
}
