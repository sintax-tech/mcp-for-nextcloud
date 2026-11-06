<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Tables;

/** Focused security/effect contract for tables_unshare_node. */
final class UnshareNodeTest extends SchemaToolTestCase {
	protected const ACTION = 'unshare_node';
	/** @return void A remaining share after a failed revoke is a difference, never a confirmed revocation. */
	public function testRemainingShareDoesNotMeanSuccessfulRevocation(): void {
		$plan = $this->flow->preview(static::ACTION, $this->arguments(), 'alice');
		$this->gateway->method('apply')->willReturn(2);
		$this->gateway->method('observe')->willReturn(['id' => 2, 'permissions' => ['read' => true]]);
		$r = $this->flow->handle(static::ACTION, $this->arguments() + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice');
		$this->assertSame('different', json_decode($r['content'][0]['text'], true)['status']);
	}
}
