<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

/** Focused security/effect contract for tables_update_column. */
final class UpdateColumnTest extends SchemaToolTestCase {
	protected const ACTION = 'update_column';
	/** @return void Session divergence prevents both preview and native mutation. */
	public function testSessionDivergenceNeverReachesTheNativeBoundary(): void {
		$this->session->method('assertCaller')->willThrowException(new \RuntimeException('session mismatch'));
		$this->gateway->expects($this->never())->method('prepare');
		$this->expectException(\RuntimeException::class);
		$this->flow->preview(SchemaActionFixture::of($this, static::ACTION), $this->arguments(), 'alice');
	}
	/** @return void Type and subtype are never accepted as update fields. */
	public function testTypeChangeRefusesTheWholePatch(): void {
		$args = $this->arguments(); $args['patch']['type'] = 'text';
		$this->gateway->expects($this->never())->method('prepare');
		$this->expectException(\InvalidArgumentException::class);
		$this->flow->preview(SchemaActionFixture::of($this, static::ACTION), $args, 'alice');
	}
}
