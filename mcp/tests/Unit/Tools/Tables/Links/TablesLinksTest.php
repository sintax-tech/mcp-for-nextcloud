<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tables\Links;

use OCA\Mcp\Tests\Unit\Tools\Tables\Support\TablesScene;
use PHPUnit\Framework\TestCase;

/** Links follow the Tables search provider: the absolute `tables.page.index` route plus `#/table/<id>` or `#/view/<id>`. */
final class TablesLinksTest extends TestCase {
	/**
	 * A table and a view link.
	 *
	 * @return void
	 */
	public function testLinks(): void {
		$links = TablesScene::links($this);
		$this->assertSame(TablesScene::BASE . '#/table/7', $links->table(7));
		$this->assertSame(TablesScene::BASE . '#/view/9', $links->view(9));
	}
}
