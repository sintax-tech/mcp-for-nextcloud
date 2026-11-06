<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

/** Focused security/effect contract for tables_share_node. */
final class ShareNodeTest extends SchemaToolTestCase {
	protected const ACTION = 'share_node';
	/** @return void Session divergence prevents both preview and native mutation. */
	public function testSessionDivergenceNeverReachesTheNativeBoundary(): void {
		$this->session->method('assertCaller')->willThrowException(new \RuntimeException('session mismatch'));
		$this->gateway->expects($this->never())->method('prepare');
		$this->expectException(\RuntimeException::class);
		$this->flow->preview(static::ACTION, $this->arguments(), 'alice');
	}
}
