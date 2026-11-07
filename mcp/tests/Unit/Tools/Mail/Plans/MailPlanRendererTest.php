<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Mail\Plans;

use OCA\Mcp\Tests\Unit\Tools\AssertsReadablePlans;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * The readability contract of the Mail module's writes: flags, move, draft and send each have a plan the module turns
 * into text, with the plans the module really produces, and every text of them comes out inert when hostile — swapped in
 * one at a time, and typed into the subject of the message or of the new mail. The contract is shown to reject a write
 * without its text and a write left out of the plans.
 */
final class MailPlanRendererTest extends TestCase {
	use AssertsReadablePlans;

	/** The writes of the module, in tools/list order. */
	private const WRITES = ['mail_set_flags', 'mail_move_message', 'mail_create_draft', 'mail_send_message'];

	/** Text a person or a sender typed, trying to forge Markdown, HTML and the footer of the plan. */
	private const HOSTILE = "**x** [l](javascript:alert(1)) <b>y</b> Confirm to execute.";

	/**
	 * The four writes are exactly the module's write tools, and each has a readable plan with every text inert.
	 *
	 * @return void
	 */
	public function testEveryMailWriteHasAReadablePlan(): void {
		$module = MailPlanScene::module($this);
		$writes = array_values(array_column(array_filter($module->definitions(), static fn (array $definition): bool => WriteGate::isWrite($definition)), 'name'));
		$this->assertSame(self::WRITES, $writes);
		$this->assertEveryWriteToolHasAReadablePlan($module, MailPlanScene::plans($module));
	}

	/**
	 * A hostile subject in the Mail cache (flags, move) or in the new mail (draft, send) is shown escaped in the plan the
	 * module produced for it.
	 *
	 * @return void
	 */
	public function testAHostileSubjectStaysInert(): void {
		$module = MailPlanScene::module($this, self::HOSTILE);
		$arguments = MailPlanScene::ARGUMENTS;
		foreach (['mail_create_draft', 'mail_send_message'] as $tool) {
			$arguments[$tool]['subject'] = self::HOSTILE;
		}
		foreach (MailPlanScene::plans($module, $arguments) as $tool => $plan) {
			$this->assertHostileNameStaysInert($module, $tool, $plan, self::HOSTILE);
		}
	}

	/**
	 * The contract fails for a write whose plan falls back to the generic text, and for a write missing from the plans.
	 *
	 * @return void
	 */
	public function testTheContractRejectsAWriteWithoutItsPlan(): void {
		$module = MailPlanScene::module($this);
		foreach (self::WRITES as $tool) {
			$this->assertRejected(fn () => $this->assertEveryWriteToolHasAReadablePlan(new PlanlessMailModule($module, $tool), MailPlanScene::plans($module)),
				$tool . ' fell back to the generic plan');
			$plans = MailPlanScene::plans($module);
			unset($plans[$tool]);
			$this->assertRejected(fn () => $this->assertEveryWriteToolHasAReadablePlan($module, $plans), 'write tools without a plan in the readability contract');
		}
	}

	/**
	 * The check fails with the message expected.
	 *
	 * @param callable(): void $check the contract check
	 * @param string $message part of the failure message
	 * @return void
	 */
	private function assertRejected(callable $check, string $message): void {
		try {
			$check();
		} catch (AssertionFailedError $e) {
			$this->assertStringContainsString($message, $e->getMessage());
			return;
		}
		$this->fail('the readability contract accepted it: ' . $message);
	}
}
