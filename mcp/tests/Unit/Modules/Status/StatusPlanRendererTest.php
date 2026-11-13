<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Status;

use OCA\Mcp\Modules\Status\StatusModule;
use OCA\Mcp\Tests\Unit\Tools\AssertsReadablePlans;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/** The status type plan is text the person can read, and stored text stays inert. */
final class StatusPlanRendererTest extends TestCase {
	use AssertsReadablePlans;

	/** @return void the one write of this module renders its own plan */
	public function testEveryWriteHasAReadablePlan(): void {
		$module = new StatusModule($this->createMock(ContainerInterface::class));
		$this->assertEveryWriteToolHasAReadablePlan($module, [
			'status_set_type' => [
				'requiresConfirmation' => true,
				'action' => 'status_set_type',
				'before' => 'online',
				'after' => 'dnd',
				'message_kept' => true,
				'removes_backup' => true,
				'message' => 'This changes only your own status type. The stored message stays. A temporary backup of your status is removed, as in the status menu.',
			],
			'status_set_message' => [
				'requiresConfirmation' => true,
				'action' => 'status_set_message',
				'mode' => 'custom',
				'before' => 'At the desk',
				'after' => 'In a meeting',
				'clear_at' => 1_700_000_200,
				'removes_backup' => true,
				'message' => 'This changes only your own status message. A temporary backup of your status is removed, as in the status menu. No new emoji or icon is stored; a stored custom icon is cleared.',
			],
		]);
	}

	/** @return void a missing row is named, and the requested type is still shown */
	public function testAMissingStatusIsNamed(): void {
		$module = new StatusModule($this->createMock(ContainerInterface::class));
		$text = $module->renderPlan('status_set_type', ['before' => null, 'after' => 'online', 'message' => 'The stored message stays.']);
		self::assertIsString($text);
		self::assertStringContainsString('No status is configured.', $text);
		self::assertStringContainsString('online', $text);
	}
}
