<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Tables;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;

/**
 * The three Tables tools against the real Tables app of a Tables cell (ci/tables/cell.sh), through the real registry:
 * - each user of the scene sees exactly what the Tables app shows them (compared with its own services, enrichment
 *   included), and the read-only share of a table is told its views are not listed;
 * - a view shows only its columns, with its read-only setting, and never its table; a table or view out of reach reads
 *   exactly like a missing one;
 * - nothing changes in any table of the Tables app, and the user without tables gets no tutorial table;
 * - the answers follow the user the call is for, whoever the session holds;
 * - a user whose grant denies reading Tables has no Tables tool.
 */
#[Group('tables')]
final class McpTablesReadTest extends IntegrationTestCase {
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
	 * Gives carol back the default grant.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if (getenv('MCP_IT_WITH_TABLES') === '1') {
			Server::get(GrantPolicy::class)->setGrant(TablesCellScene::CAROL, 'tables', 'read', true);
		}
		parent::tearDown();
	}

	/**
	 * Every user sees what the Tables app shows them, through views only the views' columns, and nothing changes.
	 *
	 * @return void
	 */
	public function testEachUserSeesWhatTheTablesAppShowsAndNothingChanges(): void {
		$ids = TablesCellScene::ids();
		$probe = ['tables' => [$ids['tables']['budget'], TablesCellScene::MISSING], 'views' => [$ids['views']['approved'], $ids['views']['summary'], TablesCellScene::MISSING]];
		$before = $this->tablesAppRows();
		$this->assertSame([], $this->ownedTables(TablesCellScene::DAVE));

		$observed = [];
		foreach (TablesCellScene::USERS as $uid) {
			$observed[$uid] = TablesActor::run($uid, 'observe', $probe);
		}

		$this->assertSame($before, $this->tablesAppRows(), 'a table of the Tables app changed');
		$this->assertSame([], $this->ownedTables(TablesCellScene::DAVE), 'a tutorial table was created');

		foreach (TablesCellScene::USERS as $uid) {
			$native = TablesActor::run($uid, 'native', $probe);
			$out = $observed[$uid];
			$this->assertSame(['tables_list_tables', 'tables_list_views', 'tables_list_columns', 'tables_list_rows'], $out['tools'], $uid);
			$tables = $this->decoded($out['tables']);
			$this->assertSame(array_map('intval', array_keys($native['tables'])), array_column($tables['tables'], 'id'), "$uid: the tables the Tables app lists");
			$this->assertSame(count($native['tables']), $tables['total'], $uid);
			foreach ($tables['tables'] as $table) {
				$this->assertSame($native['tables'][(string)$table['id']] !== null, $table['viewsListed'], "$uid: views listed for table {$table['id']}");
			}
			$expectedViews = [];
			foreach ($native['tables'] as $listed) {
				$expectedViews = [...$expectedViews, ...($listed ?? [])];
			}
			$expectedViews = array_values(array_unique([...$expectedViews, ...$native['shared'], ...$native['contextViews']]));
			sort($expectedViews);
			$views = $this->decoded($out['views']);
			$viewIds = array_column($views['views'], 'id');
			sort($viewIds);
			$this->assertSame($expectedViews, $viewIds, "$uid: the views the Tables app shows");
			foreach ($native['columns'] as $key => $columns) {
				if ($columns === null) {
					$this->assertTrue($out['columns'][$key]['isError'] ?? false, "$uid: $key is out of reach");
					$this->assertSame($out['columns']['table:' . TablesCellScene::MISSING]['content'], $out['columns'][$key]['content'], "$uid: $key reads like a missing one");
					continue;
				}
				$this->assertSame($columns, array_column($this->decoded($out['columns'][$key])['columns'], 'id'), "$uid: the columns of $key");
			}
		}

		$this->assertTheSceneAsSeeded($ids, $observed);
	}

	/**
	 * The answers of the session's user never reach the user the call is for, either way round.
	 *
	 * @return void
	 */
	public function testTheAnswersFollowTheUserNotTheSession(): void {
		$ids = TablesCellScene::ids();
		$dave = TablesActor::run(TablesCellScene::DAVE, 'observe', ['session' => TablesCellScene::ALICE, 'tables' => [$ids['tables']['budget']], 'views' => [$ids['views']['approved']]]);
		$this->assertSame([], $this->decoded($dave['tables'])['tables']);
		$this->assertSame([], $this->decoded($dave['views'])['views']);
		$this->assertTrue($dave['columns']['table:' . $ids['tables']['budget']]['isError']);
		$this->assertTrue($dave['columns']['view:' . $ids['views']['approved']]['isError']);

		$alice = TablesActor::run(TablesCellScene::ALICE, 'observe', ['session' => TablesCellScene::DAVE]);
		$this->assertSame([$ids['tables']['budget'], $ids['tables']['notes']], array_column($this->decoded($alice['tables'])['tables'], 'id'));
		$this->assertSame([], $this->ownedTables(TablesCellScene::DAVE), 'a tutorial table was created');
	}

	/**
	 * Without the read grant, carol has no Tables tool and a call is refused.
	 *
	 * @return void
	 */
	public function testADeniedGrantHidesTheTools(): void {
		Server::get(GrantPolicy::class)->setGrant(TablesCellScene::CAROL, 'tables', 'read', false);
		$out = TablesActor::run(TablesCellScene::CAROL, 'observe', ['tables' => [TablesCellScene::ids()['tables']['budget']]]);
		$this->assertSame([], $out['tools']);
		$this->assertSame(['refused' => true], $out['tables']);
		$this->assertSame(['refused' => true], $out['columns']['table:' . TablesCellScene::ids()['tables']['budget']]);
	}

	/**
	 * The scene as seed.php made it, beside the comparison with the Tables app.
	 *
	 * @param array<string, mixed> $ids ids of the scene
	 * @param array<string, array<string, mixed>> $observed what each user observed
	 * @return void
	 */
	private function assertTheSceneAsSeeded(array $ids, array $observed): void {
		['budget' => $budget, 'notes' => $notes] = $ids['tables'];
		['approved' => $approved, 'summary' => $summary] = $ids['views'];
		$tables = fn (string $uid): array => array_map(static fn (array $t): array => [$t['id'], $t['ownedByMe'], $t['viewsListed']], $this->decoded($observed[$uid]['tables'])['tables']);
		$this->assertSame([[$budget, true, true], [$notes, true, true]], $tables(TablesCellScene::ALICE));
		$this->assertSame([[$budget, false, false]], $tables(TablesCellScene::CAROL));
		$this->assertSame([[$budget, false, true]], $tables(TablesCellScene::FRANK));
		foreach ([TablesCellScene::BOB, TablesCellScene::ERIN, TablesCellScene::DAVE] as $uid) {
			$this->assertSame([], $tables($uid), "$uid: a view never brings its table");
		}
		$views = fn (string $uid): array => array_map(static fn (array $v): array => [$v['id'], $v['via']], $this->decoded($observed[$uid]['views'])['views']);
		$this->assertSame([[$approved, ['shared']]], $views(TablesCellScene::BOB));
		// The application's view comes through the context; Tables 2.3 also lists it among the views shared with its members.
		$erin = $views(TablesCellScene::ERIN);
		$this->assertSame([$summary], array_column($erin, 0));
		$this->assertContains('context', $erin[0][1]);
		$this->assertSame(1, $this->decoded($observed[TablesCellScene::CAROL]['views'])['tablesWithoutListedViews']);

		$bobColumns = $this->decoded($observed[TablesCellScene::BOB]['columns']["view:$approved"]);
		$this->assertSame([$ids['columns']['item'], $ids['columns']['amount']], array_column($bobColumns['columns'], 'id'));
		$this->assertSame([false, true], array_column(array_column($bobColumns['columns'], 'view'), 'readonly'));
		$this->assertStringNotContainsString('Secret note', json_encode($bobColumns, JSON_THROW_ON_ERROR));
		$this->assertStringNotContainsString('"filter"', json_encode($this->decoded($observed[TablesCellScene::BOB]['views']), JSON_THROW_ON_ERROR));
		$carolColumns = $this->decoded($observed[TablesCellScene::CAROL]['columns']["table:$budget"]);
		$this->assertSame(array_values($ids['columns']), array_column($carolColumns['columns'], 'id'));
		$this->assertSame([['id' => 1, 'label' => 'Open'], ['id' => 2, 'label' => 'Closed']], $carolColumns['columns'][2]['options']['selectionOptions']);
	}
}
