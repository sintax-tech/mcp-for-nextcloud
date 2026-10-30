<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Handler;

use OCA\Mcp\Tests\Unit\Tools\Tables\Support\RowsScene;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesRowsWiring;
use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesScene;
use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Tables\TablesMessages;
use PHPUnit\Framework\TestCase;

/** tables_list_rows: the target with its link, the rows with authorship and visible cells, paging, and invalid calls. */
final class ListRowsHandlerTest extends TestCase {
	/**
	 * Bob's first page through the view.
	 *
	 * @return void
	 */
	public function testTheAnswer(): void {
		$answer = json_decode(TablesRowsWiring::handler($this, RowsScene::app(), 'bob')->handle(['view_id' => 31, 'limit' => 2], 'bob')['content'][0]['text'], true);
		$this->assertSame(['viewId', 'url', 'rows', 'hasMore', 'nextCursor', 'offsetLimitReached', 'truncatedCells', 'notice'], array_keys($answer));
		$this->assertSame(TablesScene::BASE . '#/view/31', $answer['url']);
		$this->assertSame(TablesMessages::UNTRUSTED_NOTICE, $answer['notice']);
		$this->assertSame(['id', 'createdBy', 'createdAt', 'lastEditBy', 'lastEditAt', 'cells'], array_keys($answer['rows'][0]));
		$this->assertSame(['columnId' => 11, 'value' => 'Alpha'], $answer['rows'][0]['cells'][0]);
		$this->assertTrue($answer['hasMore']);
		$this->assertStringStartsWith('v1.', $answer['nextCursor']);
	}

	/**
	 * Both or neither target, an empty filter, an empty group, a repeated sort column and a search starting with @ are
	 * invalid calls.
	 *
	 * @return void
	 */
	public function testInvalidCalls(): void {
		foreach ([[], ['table_id' => 1, 'view_id' => 31], ['view_id' => 31, 'filter' => []], ['view_id' => 31, 'filter' => [[]]],
			['view_id' => 31, 'sort' => [['column_id' => 12, 'mode' => 'ASC'], ['column_id' => 12, 'mode' => 'DESC']]], ['view_id' => 31, 'search' => '@me']] as $args) {
			try {
				TablesRowsWiring::handler($this, RowsScene::app(), 'bob')->handle($args, 'bob');
				$this->fail('accepted ' . json_encode($args));
			} catch (ArgumentValidationException | \OCA\Mcp\Tools\ToolFailure) {
				$this->addToAssertionCount(1);
			}
		}
	}
}
