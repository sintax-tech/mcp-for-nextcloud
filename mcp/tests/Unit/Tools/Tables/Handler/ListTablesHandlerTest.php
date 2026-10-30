<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Handler;

use OCA\Mcp\Tests\Unit\Tools\Tables\Support\FakeTablesApp;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesHandlers;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesScene;
use OCA\Mcp\Tools\Tables\TablesMessages;
use PHPUnit\Framework\TestCase;

/**
 * tables_list_tables: one page of the tables the user can open, with cleaned text, whether their views are listed and a
 * link, nothing about other users, and an honest count.
 */
final class ListTablesHandlerTest extends TestCase {
	/**
	 * The owner sees both tables, cleaned, with links; a read-only share sees the table, not owned, views not listed.
	 *
	 * @return void
	 */
	public function testTheTablesOfEachUser(): void {
		$app = TablesScene::app();
		$alice = TablesHandlers::answer($this, $app, 'tables_list_tables', [], 'alice');
		$this->assertSame([
			'tables' => [
				['id' => 1, 'title' => 'Budget', 'emoji' => '💰', 'description' => 'Team budget', 'archived' => false, 'ownedByMe' => true, 'viewsListed' => true,
					'url' => TablesScene::BASE . '#/table/1'],
				['id' => 3, 'title' => 'Archive', 'emoji' => '', 'description' => '', 'archived' => true, 'ownedByMe' => true, 'viewsListed' => true,
					'url' => TablesScene::BASE . '#/table/3'],
			],
			'total' => 2, 'hasMore' => false, 'nextOffset' => null, 'notice' => TablesMessages::UNTRUSTED_NOTICE,
		], $alice);
		$carol = TablesHandlers::answer($this, $app, 'tables_list_tables', [], 'carol');
		$this->assertSame([[1, false, false]], array_map(static fn (array $t): array => [$t['id'], $t['ownedByMe'], $t['viewsListed']], $carol['tables']));
		$this->assertSame(['tables' => [], 'total' => 0, 'hasMore' => false, 'nextOffset' => null, 'notice' => TablesMessages::UNTRUSTED_NOTICE],
			TablesHandlers::answer($this, $app, 'tables_list_tables', [], 'dave'));
		$this->assertSame([], $app->writes, 'no tutorial table for anybody');
	}

	/**
	 * limit and offset page through the list in id order and say where the next page starts.
	 *
	 * @return void
	 */
	public function testPaging(): void {
		$app = new FakeTablesApp();
		foreach (range(1, 5) as $id) {
			$app->withTable($id, 'alice', "T$id");
		}
		$first = TablesHandlers::answer($this, $app, 'tables_list_tables', ['limit' => 2, 'offset' => 0], 'alice');
		$this->assertSame([[1, 2], 5, true, 2], [array_column($first['tables'], 'id'), $first['total'], $first['hasMore'], $first['nextOffset']]);
		$last = TablesHandlers::answer($this, $app, 'tables_list_tables', ['limit' => 2, 'offset' => 4], 'alice');
		$this->assertSame([[5], 5, false, null], [array_column($last['tables'], 'id'), $last['total'], $last['hasMore'], $last['nextOffset']]);
	}
}
