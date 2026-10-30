<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Handler;

use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesHandlers;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesScene;
use OCA\Mcp\Tools\Tables\TablesMessages;
use PHPUnit\Framework\TestCase;

/**
 * tables_list_views: one page of the views the user can open, with where each was found and a link, and how many of the
 * user's tables have views that are not listed, so the limit is never silent.
 */
final class ListViewsHandlerTest extends TestCase {
	/**
	 * A view share sees its view and nothing of the table but its id; a read-only share sees no views and is told one of
	 * its tables has views not listed.
	 *
	 * @return void
	 */
	public function testTheViewsOfEachUser(): void {
		$app = TablesScene::app();
		$this->assertSame([
			'views' => [['id' => 21, 'tableId' => 1, 'title' => 'Approved', 'emoji' => '', 'description' => '', 'columnCount' => 2, 'via' => ['shared'],
				'url' => TablesScene::BASE . '#/view/21']],
			'total' => 1, 'hasMore' => false, 'nextOffset' => null, 'tablesWithoutListedViews' => 0, 'notice' => TablesMessages::UNTRUSTED_NOTICE,
		], TablesHandlers::answer($this, $app, 'tables_list_views', [], 'bob'));
		$carol = TablesHandlers::answer($this, $app, 'tables_list_views', [], 'carol');
		$this->assertSame([[], 1], [$carol['views'], $carol['tablesWithoutListedViews']]);
		$erin = TablesHandlers::answer($this, $app, 'tables_list_views', [], 'erin');
		$this->assertSame([[22, ['context']]], array_map(static fn (array $v): array => [$v['id'], $v['via']], $erin['views']));
	}

	/**
	 * limit and offset page through the views.
	 *
	 * @return void
	 */
	public function testPaging(): void {
		$page = TablesHandlers::answer($this, TablesScene::app(), 'tables_list_views', ['limit' => 1, 'offset' => 1], 'alice');
		$this->assertSame([[22], 2, false, null], [array_column($page['views'], 'id'), $page['total'], $page['hasMore'], $page['nextOffset']]);
	}
}
