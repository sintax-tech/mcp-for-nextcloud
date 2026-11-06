<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

/** Focused security/effect contract for tables_update_table. */
final class UpdateTableTest extends SchemaToolTestCase {
	protected const ACTION = 'update_table';
	/** @return void A schema grant does not require the MCP read grant. */
	public function testWriteDoesNotRequireTheReadToolGrant(): void {
		$this->grants->setGrant('alice', 'tables', 'read', false);
		$this->assertTrue($this->flow->preview(static::ACTION, $this->arguments(), 'alice')['requiresConfirmation']);
	}
}
