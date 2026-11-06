<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

/** Focused security/effect contract for tables_create_column. */
final class CreateColumnTest extends SchemaToolTestCase {
	protected const ACTION = 'create_column';
	/** @return void Every nested view identifier is strictly typed. */
	public function testViewIdsHaveNoCoercion(): void {
		$this->gateway->expects($this->never())->method('prepare');
		$this->expectException(\InvalidArgumentException::class);
		$this->flow->preview(static::ACTION, $this->arguments() + ['view_ids' => ['2']], 'alice');
	}
}
