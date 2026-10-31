<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Polls;

use OCA\Mcp\Tools\PlanChanged;
use OCA\Mcp\Tools\PlanRenderer;
use OCA\Mcp\Tools\Polls\Create\CreatePlan;
use PHPUnit\Framework\TestCase;

/**
 * A Polls version that moves between the plan and the confirmation must show up in the warning text.
 */
final class PlanChangedNoticeTest extends TestCase {
	/**
	 * The new plan names the installed version and the rendered answer carries a warning.
	 *
	 * @return void
	 */
	public function testAChangedPollsVersionIsNamedInTheWarning(): void {
		$rig = new PollsCreateRig($this);
		$args = ['type' => 'textPoll', 'title' => 'Almoço', 'options' => [['text' => 'Pizza']]];
		$plan = $rig->handler->preview($args, 'alice');
		$before = PlanRenderer::render($rig->base->module, CreatePlan::TOOL, $plan);
		self::assertStringNotContainsString('### ', $before);
		self::assertStringContainsString('version: 9.3.0', $before);
		$rig->base->version = '9.3.1';
		try {
			$rig->confirm($args, $plan);
			self::fail('the old Polls version was confirmed');
		} catch (PlanChanged $changed) {
			$message = $changed->plan['warnings'][0]['message'] ?? '';
			self::assertNotSame('', $message);
			self::assertStringContainsString('version', $message);
			self::assertStringContainsString('9.3.1', $message);
			$text = PlanRenderer::render($rig->base->module, CreatePlan::TOOL, $changed->plan);
			self::assertStringContainsString('### ', $text);
			self::assertStringContainsString('Warnings', $text);
			self::assertStringContainsString('version', $text);
			self::assertStringContainsString('9.3.1', $text);
			self::assertNotSame($before, $text);
			self::assertSame([], NativeCreateScene::$effects);
		}
	}
}
