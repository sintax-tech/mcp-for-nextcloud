<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Polls;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Shared guard/effect contracts for every tool, with focused cases for operation-specific rules elsewhere. */
final class PollsMutationFlowTest extends TestCase {
	/** @return iterable<string,array{string,array<string,mixed>}> Tools and valid input. */
	public static function tools(): iterable { return PollsMutationCases::all(); }
	/** @param string $tool Tool name. @param array<string,mixed> $args Input. @return void */
	#[DataProvider('tools')]
	public function testPreviewThenConfirmedFreshReadback(string $tool, array $args): void {
		$s = new PollsMutationScene($tool); $flow = PollsMutationCases::flow($this, $s);
		$plan = $flow->run($tool, $args, 'alice', true);
		self::assertSame(0, $s->writes); self::assertSame(1, $s->closes);
		$out = $flow->run($tool, $args + ['confirm' => true, 'plan_state' => $plan['plan_state']], 'alice', false);
		self::assertSame('applied', $out['status']); self::assertTrue($out['nativeCallAttempted']);
		self::assertSame(1, $s->writes); self::assertGreaterThanOrEqual(3, $s->reads);
		self::assertStringNotContainsString('token', json_encode($out));
	}
	/** @param string $tool Tool name. @param array<string,mixed> $args Input. @return void */
	#[DataProvider('tools')]
	public function testStrictConfirmationAndClosedDirectInput(string $tool, array $args): void {
		foreach ([[], ['confirm' => false], ['confirm' => 'true'], ['confirm' => true], ['confirm' => true, 'plan_state' => 'forged']] as $extra) {
			$s = new PollsMutationScene($tool); $flow = PollsMutationCases::flow($this, $s);
			try { $flow->run($tool, $args + $extra, 'alice', false); self::fail('accepted invalid confirmation'); }
			catch (ToolFailure|ArgumentValidationException|PlanChanged) { self::assertSame(0, $s->writes); }
		}
	}
	/** @param string $tool Tool name. @param array<string,mixed> $args Input. @return void */
	#[DataProvider('tools')]
	public function testChangedSnapshotRequiresNewPlan(string $tool, array $args): void {
		$s = new PollsMutationScene($tool); $flow = PollsMutationCases::flow($this, $s);
		$p = $flow->run($tool, $args, 'alice', true); $s->state['poll']['title'] = 'Concurrent edit';
		$this->expectException(PlanChanged::class);
		try { $flow->run($tool, $args + ['confirm' => true, 'plan_state' => $p['plan_state']], 'alice', false); }
		finally { self::assertSame(0, $s->writes); }
	}
	/** @param string $tool Tool name. @param array<string,mixed> $args Input. @return void */
	#[DataProvider('tools')]
	public function testRevocationBeforeSinkIsRefusal(string $tool, array $args): void {
		$s = new PollsMutationScene($tool); $flow = PollsMutationCases::flow($this, $s);
		$p = $flow->run($tool, $args, 'alice', true); $s->preflightFails = true;
		$this->expectException(ToolFailure::class);
		try { $flow->run($tool, $args + ['confirm' => true, 'plan_state' => $p['plan_state']], 'alice', false); }
		finally { self::assertSame(0, $s->writes); }
	}
	/** @param string $tool Tool name. @param array<string,mixed> $args Input. @return void */
	#[DataProvider('tools')]
	public function testPersistedThenThrownIsObservedWithoutRetry(string $tool, array $args): void {
		$s = new PollsMutationScene($tool); $flow = PollsMutationCases::flow($this, $s);
		$p = $flow->run($tool, $args, 'alice', true); $s->throwAfter = true;
		$out = $flow->run($tool, $args + ['confirm' => true, 'plan_state' => $p['plan_state']], 'alice', false);
		self::assertSame('applied', $out['status']); self::assertSame(1, $s->writes);
	}
	/** @param string $tool Tool name. @param array<string,mixed> $args Input. @return void */
	#[DataProvider('tools')]
	public function testReadbackRevocationWithholdsEveryOldField(string $tool, array $args): void {
		$s = new PollsMutationScene($tool); $flow = PollsMutationCases::flow($this, $s);
		$p = $flow->run($tool, $args, 'alice', true); $s->denyAfter = true;
		$out = $flow->run($tool, $args + ['confirm' => true, 'plan_state' => $p['plan_state']], 'alice', false);
		self::assertSame('uncertain', $out['status']); self::assertArrayNotHasKey('observed', $out); self::assertArrayNotHasKey('requested', $out);
		self::assertSame(1, $s->writes);
	}
}
