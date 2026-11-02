<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Tables\Write;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCA\Mcp\Tests\Integration\Tables\TablesCellRows;
use OCA\Mcp\Modules\Tables\Presentation\TablesMessages;
use OCA\Mcp\Modules\Tables\Presentation\RowWriteMessages;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/**
 * tables_create_row and tables_update_row against the real Tables app of a Tables cell (ci/tables/seed-write.php), through
 * the real registry, each call in its own process with the actor's session and the Tables services the DI builds for it:
 * - the owner creates through the table: the plan writes nothing, the confirmed call writes through RowService, the
 *   answer's values are the ones RowService returned, and RowService reads every value back as planned, with the actor as
 *   createdBy and lastEditBy;
 * - a share of the view that only allows creating creates through the view without reading any row, and the hidden value
 *   the view adds is written by Tables and never shown;
 * - the editor of the view updates a row of the view; everything refused (another target, a row out of the view, read
 *   only, a read-only or hidden column, an option that does not exist, a view whose hidden rule names another table,
 *   another session) leaves every table of the Tables app as it was;
 * - a limit changed between the plan and the confirmation gives a new plan and writes nothing;
 * - a listener of the Tables app's own RowAddedEvent that fails after the insert is reported as uncertain, and the row is
 *   there: the event comes from the native path;
 * - a missing number is refused before Tables 2.3.2, whose number mapper would store 0, on a creation and on an update,
 *   with every table as it was, and empties the cell on 2.3.2; a hidden stars value the view adds outside the column's
 *   limits is refused, one at the limit is written by Tables.
 */
#[Group('tables')]
final class McpTablesWriteTest extends IntegrationTestCase {
	use TablesCellRows;

	/**
	 * Runs only in a Tables cell; the writing users get the row write grants.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		if (getenv('MCP_IT_WITH_TABLES') !== '1') {
			$this->markTestSkipped('Tables cell only (MCP_IT_WITH_TABLES=1)');
		}
		$policy = Server::get(GrantPolicy::class);
		foreach (WriteCellScene::USERS as $uid) {
			$policy->setGrant($uid, 'tables', 'create', true);
			$policy->setGrant($uid, 'tables', 'edit', true);
		}
	}

	/**
	 * The owner's plan writes nothing; the confirmation writes one row whose values RowService returns and reads back as
	 * planned, by the owner.
	 *
	 * @return void
	 */
	public function testTheOwnerCreatesAndTablesReadsTheValuesBack(): void {
		$ids = WriteCellScene::ids();
		$c = $ids['columns'];
		$arguments = ['table_id' => $ids['tables']['plans'], 'cells' => [
			['column_id' => $c['title'], 'value' => 'Gamma'], ['column_id' => $c['amount'], 'value' => 12.5], ['column_id' => $c['status'], 'value' => 2],
			['column_id' => $c['tags'], 'value' => [10, 1]], ['column_id' => $c['done'], 'value' => false], ['column_id' => $c['rating'], 'value' => 0],
			['column_id' => $c['due'], 'value' => '2026-11-02'], ['column_id' => $c['site'], 'value' => ['url' => 'https://example.org/a', 'title' => 'Docs']],
		]];
		$before = $this->tablesAppRows();
		$plan = WriteActor::run(WriteCellScene::OWNER, 'call', ['tool' => 'tables_create_row', 'arguments' => $arguments]);
		$this->assertFalse($plan['isError'], $plan['text']);
		$this->assertTrue($plan['structured']['requiresConfirmation']);
		$this->assertSame($before, $this->tablesAppRows(), 'a plan writes nothing');
		$string = in_array((string)getenv('MCP_IT_TABLES_VERSION'), ['1.0.10', '2.3.2'], true);
		$after = array_column($plan['structured']['cells'], 'after', 'columnId');
		$this->assertSame(['Gamma', 12.5, $string ? '2' : 2, [1, 10], 'false', 0, '2026-11-02', '{"title":"Docs","value":"https://example.org/a","providerId":"url"}'],
			array_values($after), 'the effect each pinned parser gives');

		$confirmed = WriteActor::run(WriteCellScene::OWNER, 'call', ['tool' => 'tables_create_row',
			'arguments' => $arguments + ['confirm' => true, 'plan_state' => $plan['structured']['plan_state']]]);
		$this->assertFalse($confirmed['isError'], $confirmed['text']);
		$answer = json_decode($confirmed['text'], true, 512, JSON_THROW_ON_ERROR);
		foreach ($answer['cells'] as $cell) {
			$this->assertSame($after[$cell['columnId']], $cell['requested'], 'requested ' . $cell['columnId']);
			$this->assertSame($cell['requested'], $cell['returned'], 'returned by RowService ' . $cell['columnId']);
		}
		$row = self::row(WriteCellScene::OWNER, $ids['tables']['plans'], (int)$answer['target']['rowId']);
		$this->assertSame([WriteCellScene::OWNER, WriteCellScene::OWNER], [$row['createdBy'], $row['lastEditBy']], 'the actor, through the service the DI built for it');
		$read = array_column($row['data'], 'value', 'columnId');
		$this->assertSame(['Gamma', 12.5, '2', [1, 10], 'false', 0, '2026-11-02', '{"title":"Docs","value":"https://example.org/a","providerId":"url"}'],
			[$read[$c['title']], (float)$read[$c['amount']], (string)$read[$c['status']], $read[$c['tags']], $read[$c['done']], (int)$read[$c['rating']], $read[$c['due']], $read[$c['site']]],
			'read back by RowService (the single selection and the numbers as the formatter types them)');
	}

	/**
	 * A share of the view that only allows creating: the creation needs no read of any row, the hidden Secret is set to
	 * "ok" by Tables, and neither the plan nor the answer shows it.
	 *
	 * @return void
	 */
	public function testACreateOnlyShareCreatesThroughTheView(): void {
		$ids = WriteCellScene::ids();
		$c = $ids['columns'];
		$run = WriteActor::planAndConfirm(WriteCellScene::VIEWER, 'tables_create_row', ['view_id' => $ids['views']['write'],
			'cells' => [['column_id' => $c['title'], 'value' => 'From the viewer'], ['column_id' => $c['amount'], 'value' => 3]]], ['confirm_shared' => true]);
		$this->assertFalse($run['plan']['isError'], $run['plan']['text']);
		$this->assertFalse($run['result']['isError'], $run['result']['text']);
		foreach ([$run['plan']['text'], json_encode($run['plan']['structured'], JSON_THROW_ON_ERROR), $run['result']['text']] as $shown) {
			$this->assertStringNotContainsString('Secret', $shown);
			$this->assertStringNotContainsString('"columnId":' . $c['secret'] . ',', $shown);
		}
		$rowId = (int)json_decode($run['result']['text'], true, 512, JSON_THROW_ON_ERROR)['target']['rowId'];
		$read = array_column(self::row(WriteCellScene::OWNER, $ids['tables']['plans'], $rowId)['data'], 'value', 'columnId');
		$this->assertSame(['From the viewer', 'ok'], [$read[$c['title']], $read[$c['secret']]], 'Tables added the hidden value of the view');
		$this->assertSame(WriteCellScene::VIEWER, self::row(WriteCellScene::OWNER, $ids['tables']['plans'], $rowId)['createdBy']);
	}

	/**
	 * The editor of the view changes a row in the view; every refusal leaves the Tables app's tables as they were.
	 *
	 * @return void
	 */
	public function testUpdatesAndRefusals(): void {
		$ids = WriteCellScene::ids();
		$c = $ids['columns'];
		[$alpha, $beta] = $ids['rows'];
		$view = $ids['views']['write'];
		$plans = $ids['tables']['plans'];
		$before = $this->tablesAppRows();
		foreach ([
			[WriteCellScene::VIEWER, 'tables_update_row', ['view_id' => $view, 'row_id' => $alpha, 'cells' => [['column_id' => $c['amount'], 'value' => 1]]], TablesMessages::notFound()],
			[WriteCellScene::EDITOR, 'tables_update_row', ['table_id' => $plans, 'row_id' => $alpha, 'cells' => [['column_id' => $c['amount'], 'value' => 1]]], TablesMessages::notFound()],
			[WriteCellScene::EDITOR, 'tables_update_row', ['view_id' => $view, 'row_id' => $beta, 'cells' => [['column_id' => $c['amount'], 'value' => 1]]], TablesMessages::notFound()],
			[WriteCellScene::READER, 'tables_update_row', ['table_id' => $plans, 'row_id' => $alpha, 'cells' => [['column_id' => $c['amount'], 'value' => 1]]], TablesMessages::notFound()],
			[WriteCellScene::OWNER, 'tables_update_row', ['view_id' => $view, 'row_id' => $alpha, 'cells' => [['column_id' => $c['notes'], 'value' => 'x']]], RowWriteMessages::columnRefused()],
			[WriteCellScene::OWNER, 'tables_create_row', ['view_id' => $view, 'cells' => [['column_id' => $c['title'], 'value' => 'x'], ['column_id' => $c['secret'], 'value' => 'x']]], RowWriteMessages::columnRefused()],
			[WriteCellScene::OWNER, 'tables_create_row', ['table_id' => $plans, 'cells' => [['column_id' => $c['title'], 'value' => 'x'], ['column_id' => $c['status'], 'value' => 5]]], RowWriteMessages::valueRefused()],
			[WriteCellScene::VIEWER, 'tables_create_row', ['view_id' => $ids['views']['foreign'], 'cells' => [['column_id' => $c['title'], 'value' => 'x']]], RowWriteMessages::viewDefaultsRefused()],
		] as $i => [$uid, $tool, $arguments, $message]) {
			$answer = WriteActor::run($uid, 'call', ['tool' => $tool, 'arguments' => $arguments]);
			$this->assertSame([true, $message], [$answer['isError'], $answer['text']], "case $i");
		}
		$mismatch = WriteActor::run(WriteCellScene::EDITOR, 'call', ['session' => WriteCellScene::OWNER, 'tool' => 'tables_update_row',
			'arguments' => ['view_id' => $view, 'row_id' => $alpha, 'cells' => [['column_id' => $c['amount'], 'value' => 1]]]]);
		$this->assertSame([true, RowWriteMessages::sessionMismatch()], [$mismatch['isError'], $mismatch['text']]);
		$this->assertSame($before, $this->tablesAppRows(), 'a refusal writes nothing');

		$run = WriteActor::planAndConfirm(WriteCellScene::EDITOR, 'tables_update_row', ['view_id' => $view, 'row_id' => $alpha,
			'cells' => [['column_id' => $c['amount'], 'value' => 25]]], ['confirm_shared' => true]);
		$cell = $run['plan']['structured']['cells'][0];
		// Numbers travel as JSON: 10.0 and 25.0 arrive as 10 and 25, so the values are compared as numbers.
		$this->assertSame([$c['amount'], 'Amount', 10.0, 25.0], [$cell['columnId'], $cell['column'], (float)$cell['before'], (float)$cell['after']]);
		$this->assertFalse($run['result']['isError'], $run['result']['text']);
		$row = self::row(WriteCellScene::OWNER, $plans, $alpha);
		$this->assertSame([25.0, WriteCellScene::EDITOR], [(float)array_column($row['data'], 'value', 'columnId')[$c['amount']], $row['lastEditBy']]);
	}

	/**
	 * A limit of the column changed between the plan and the confirmation, the value still valid: the answer is the new
	 * plan and nothing is written.
	 *
	 * @return void
	 */
	public function testAChangedLimitGivesANewPlan(): void {
		$ids = WriteCellScene::ids();
		$c = $ids['columns'];
		$arguments = ['table_id' => $ids['tables']['plans'], 'row_id' => $ids['rows'][1], 'cells' => [['column_id' => $c['amount'], 'value' => 20]]];
		$plan = WriteActor::run(WriteCellScene::OWNER, 'call', ['tool' => 'tables_update_row', 'arguments' => $arguments]);
		$previous = WriteActor::run(WriteCellScene::OWNER, 'column-limit', ['column' => $c['amount'], 'numberMax' => 2000])['previous'];
		try {
			$before = $this->tablesAppRows();
			$confirmed = WriteActor::run(WriteCellScene::OWNER, 'call', ['tool' => 'tables_update_row',
				'arguments' => $arguments + ['confirm' => true, 'plan_state' => $plan['structured']['plan_state']]]);
			$this->assertFalse($confirmed['isError'], $confirmed['text']);
			$this->assertNotSame('', $confirmed['structured']['warnings'][0]['message']);
			$this->assertStringContainsString('numberMax', $confirmed['structured']['warnings'][0]['message']);
			$this->assertStringContainsString('2000', $confirmed['text']);
			$this->assertNotSame($plan['structured']['plan_state'], $confirmed['structured']['plan_state']);
			$this->assertSame($before, $this->tablesAppRows(), 'a changed state writes nothing');
		} finally {
			WriteActor::run(WriteCellScene::OWNER, 'column-limit', ['column' => $c['amount'], 'numberMax' => $previous]);
		}
	}

	/**
	 * A listener of RowAddedEvent that fails after RowService inserted the row: the answer is uncertain, and the row is
	 * there.
	 *
	 * @return void
	 */
	public function testAFailureAfterTheInsertIsUncertain(): void {
		$ids = WriteCellScene::ids();
		$plans = $ids['tables']['plans'];
		$count = count(WriteActor::run(WriteCellScene::OWNER, 'native', ['table_id' => $plans])['rows']);
		$run = WriteActor::planAndConfirm(WriteCellScene::OWNER, 'tables_create_row', ['table_id' => $plans,
			'cells' => [['column_id' => $ids['columns']['title'], 'value' => 'After the insert']]], ['failAfterInsert' => true]);
		$this->assertSame([true, RowWriteMessages::createUncertain()], [$run['result']['isError'], $run['result']['text']]);
		$rows = WriteActor::run(WriteCellScene::OWNER, 'native', ['table_id' => $plans])['rows'];
		$this->assertCount($count + 1, $rows, 'written before the listener failed');
	}

	/**
	 * A null number and the hidden stars values of a view, against the pinned release's mapper and the column's limits.
	 *
	 * @return void
	 */
	public function testNullNumbersAndHiddenStars(): void {
		$ids = WriteCellScene::ids();
		$c = $ids['columns'];
		$plans = $ids['tables']['plans'];
		$beta = $ids['rows'][1];
		$keepsEmpty = version_compare((string)getenv('MCP_IT_TABLES_VERSION'), '2.3.2', '>=');
		$create = ['table_id' => $plans, 'cells' => [['column_id' => $c['title'], 'value' => 'No amount'], ['column_id' => $c['amount'], 'value' => null]]];
		$update = ['table_id' => $plans, 'row_id' => $beta, 'cells' => [['column_id' => $c['amount'], 'value' => null]]];
		$before = $this->tablesAppRows();
		if (!$keepsEmpty) {
			foreach ([['tables_create_row', $create], ['tables_update_row', $update]] as [$tool, $arguments]) {
				$answer = WriteActor::run(WriteCellScene::OWNER, 'call', ['tool' => $tool, 'arguments' => $arguments]);
				$this->assertSame([true, RowWriteMessages::valueRefused()], [$answer['isError'], $answer['text']], $tool);
			}
			$this->assertSame($before, $this->tablesAppRows(), 'a null number is refused before anything is written');
		} else {
			$run = WriteActor::planAndConfirm(WriteCellScene::OWNER, 'tables_create_row', $create);
			$this->assertFalse($run['result']['isError'], $run['result']['text']);
			$rowId = (int)json_decode($run['result']['text'], true, 512, JSON_THROW_ON_ERROR)['target']['rowId'];
			$read = array_column(self::row(WriteCellScene::OWNER, $plans, $rowId)['data'], 'value', 'columnId');
			$this->assertNull($read[$c['amount']] ?? null, 'kept empty by the 2.3.2 number mapper');
		}

		$before = $this->tablesAppRows();
		$refused = WriteActor::run(WriteCellScene::OWNER, 'call', ['tool' => 'tables_create_row',
			'arguments' => ['view_id' => $ids['views']['stars'], 'cells' => [['column_id' => $c['title'], 'value' => 'Below the limit']]]]);
		$this->assertSame([true, RowWriteMessages::viewDefaultsRefused()], [$refused['isError'], $refused['text']]);
		$this->assertStringNotContainsString('Level', $refused['text']);
		$this->assertSame($before, $this->tablesAppRows(), 'the hidden value outside the limits never reaches Tables');
		$run = WriteActor::planAndConfirm(WriteCellScene::OWNER, 'tables_create_row', ['view_id' => $ids['views']['starsBound'],
			'cells' => [['column_id' => $c['title'], 'value' => 'At the limit']]]);
		$this->assertFalse($run['result']['isError'], $run['result']['text']);
		$rowId = (int)json_decode($run['result']['text'], true, 512, JSON_THROW_ON_ERROR)['target']['rowId'];
		$read = array_column(self::row(WriteCellScene::OWNER, $plans, $rowId)['data'], 'value', 'columnId');
		$this->assertSame(3, (int)$read[$c['level']], 'Tables wrote the hidden value at the limit');
	}

	/**
	 * One row as RowService gives it to a user.
	 *
	 * @param string $uid the user
	 * @param int $tableId the table
	 * @param int $rowId the row
	 * @return array{id:int, createdBy:string, lastEditBy:string, data:list<array{columnId:int, value:mixed}>} the row
	 */
	private static function row(string $uid, int $tableId, int $rowId): array {
		foreach (WriteActor::run($uid, 'native', ['table_id' => $tableId])['rows'] as $row) {
			if ($row['id'] === $rowId) {
				return $row;
			}
		}
		self::fail("row $rowId not found");
	}
}
