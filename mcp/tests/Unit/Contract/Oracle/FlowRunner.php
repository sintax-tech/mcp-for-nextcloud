<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\Oracle;

use PHPUnit\Framework\TestCase;

/**
 * Runs the steps every critical write flow is frozen by, in a fixed order, and writes their transcript (request,
 * endpoint answer, observed effect) to `effects/<module>.<flow>.json`:
 *
 * 1. `preview` — the call without confirm answers the plan and changes nothing;
 * 2. `confirm-not-boolean` / `confirm-false` — only the boolean true confirms; anything else writes nothing;
 * 3. `plan-changed` — the world changes after the plan; the old confirmation writes nothing and answers the new plan;
 * 4. `confirmed` — a fresh plan confirmed with confirm: true (and its plan_state) has the effect;
 * 5. `no-grant` — with the grant revoked a confirmation valid a moment before is refused as an unknown tool and
 *    writes nothing;
 * 6. `guest` / `guest-confirmed` — a guest whose Guests app list holds only Files (which Guests always allows)
 *    asks for the plan, then sends a confirmation valid a moment before: outside Files both are refused as an unknown
 *    tool and write nothing; a Files tool stays available to the guest;
 * 7. `hidden` — with the target hidden from MCP a confirmation valid a moment before is refused and writes nothing.
 *
 * Besides the golden, the runner asserts the invariants directly, so a regenerated golden can never bless a write
 * that happened where none may.
 */
final class FlowRunner {
	/** Directory of the effect transcripts. */
	private const DIR = __DIR__ . '/../effects/';
	/** The only app of the guest step: Guests always allows Files. */
	private const GUEST_APPS = ['files'];

	/** @var list<array<string, mixed>> transcript of the flow being run */
	private array $steps = [];

	/**
	 * @param TestCase $test the running test, for its assertions
	 * @param EffectsGate $gate the endpoint, already serving the module under test
	 * @param string $file golden file name part: the module of the contract
	 */
	public function __construct(private TestCase $test, private EffectsGate $gate, private string $file) {
	}

	/**
	 * @param CriticalFlow $flow the flow to run
	 * @return void
	 */
	public function run(CriticalFlow $flow): void {
		$this->steps = [];
		$initial = ($flow->state)();
		$plan = $this->step('preview', $flow, $flow->arguments(), $initial);
		$this->step('confirm-not-boolean', $flow, $flow->arguments() + ['confirm' => 'true'], $initial);
		$this->step('confirm-false', $flow, $flow->arguments() + ['confirm' => false], $initial);
		if ($flow->changePlan !== null) {
			$stale = $flow->confirmed($plan['body']);
			($flow->changePlan)();
			$replan = $this->step('plan-changed', $flow, $stale, ($flow->state)());
			$this->test->assertTrue($replan['body']->result->structuredContent->requiresConfirmation ?? false,
				$flow->tool . ': a changed plan must answer the new plan: ' . json_encode($replan['body']) . json_encode($this->gate->logged()));
		} else {
			$this->skip('plan-changed', $flow->changeSkipped);
		}
		$before = ($flow->state)();
		$fresh = $this->step('preview-before-confirm', $flow, $flow->arguments(), $before);
		$confirmed = $flow->confirmed($fresh['body']);
		$executed = $this->step('confirmed', $flow, $confirmed, null);
		$this->test->assertFalse(isset($executed['body']->error) || ($executed['body']->result->isError ?? false),
			$flow->tool . ': confirmed call failed: ' . json_encode($executed));
		$after = ($flow->state)();
		$this->test->assertNotEquals($before, $after, $flow->tool . ': the confirmed call had no observable effect');
		// Each refusal is asked with a confirmation that would succeed right now, so only the refusal itself stops it.
		$current = $this->confirmable($flow);
		$this->gate->grant($flow->module, $flow->operation, false);
		$denied = $this->step('no-grant', $flow, $current, $after);
		$this->test->assertSame('Unknown tool', $denied['body']->error->message ?? null, $flow->tool . ': no grant must read as no tool: ' . json_encode($denied['body']));
		$this->gate->grant($flow->module, $flow->operation, true);
		$after = $this->guest($flow, $after);
		if ($flow->hide !== null) {
			$current = $this->confirmable($flow);
			($flow->hide)();
			$hidden = $this->step('hidden', $flow, $current, $after);
			$this->test->assertTrue(($hidden['body']->result->isError ?? false) || isset($hidden['body']->error),
				$flow->tool . ': a hidden target must be refused: ' . json_encode($hidden['body']));
			($flow->unhide)();
		} else {
			$this->skip('hidden', $flow->hideSkipped);
		}
		GoldenFile::assertMatches($this->test, self::DIR . $this->file . '.' . $flow->name . '.json',
			VolatileFields::normalize(['tool' => $flow->tool, 'grant' => $flow->module . '.' . $flow->operation, 'steps' => $this->steps],
				$flow->literals()));
	}

	/**
	 * The two guest steps: the plan, then a confirmation that would succeed for a regular account.
	 *
	 * @param CriticalFlow $flow the flow
	 * @param mixed $after the state before the guest steps
	 * @return mixed the state after them (changed only by a Files tool, which Guests allows)
	 */
	private function guest(CriticalFlow $flow, mixed $after): mixed {
		$allowed = $flow->module === 'files';
		$current = $this->confirmable($flow);
		$this->gate->guest(self::GUEST_APPS);
		try {
			$plan = $this->step('guest', $flow, $flow->arguments(), $after);
			$write = $this->step('guest-confirmed', $flow, $current, $allowed ? null : $after);
		} finally {
			$this->gate->guest(null);
		}
		if (!$allowed) {
			foreach (['guest' => $plan, 'guest-confirmed' => $write] as $name => $answer) {
				$this->test->assertSame('Unknown tool', $answer['body']->error->message ?? null,
					$flow->tool . ': ' . $name . ': Guests must hide the app: ' . json_encode($answer['body']));
			}
		}
		return ($flow->state)();
	}

	/**
	 * Calls the tool once, records request, answer and effect, and checks the effect when one is expected.
	 *
	 * @param string $name step name
	 * @param CriticalFlow $flow the flow
	 * @param array<string, mixed> $arguments arguments of this call
	 * @param mixed $unchanged the state that must hold after the call; null when the step is allowed to write
	 * @return array{status:int, body:mixed} the endpoint answer
	 */
	private function step(string $name, CriticalFlow $flow, array $arguments, mixed $unchanged): array {
		$response = $this->gate->call($flow->tool, $arguments);
		$state = ($flow->state)();
		if ($unchanged !== null) {
			$this->test->assertEquals($unchanged, $state, $flow->tool . ': step ' . $name . ' changed something');
		}
		$this->steps[] = ['step' => $name, 'arguments' => $arguments, 'response' => $response, 'state' => $state];
		return $response;
	}

	/**
	 * A plan asked now, unrecorded, turned into the arguments that confirm it; the plan must be a real plan, so a flow
	 * whose effect consumed its target has to point its arguments at a target that still exists.
	 *
	 * @param CriticalFlow $flow the flow
	 * @return array<string, mixed> arguments of a confirmation valid at this moment
	 */
	private function confirmable(CriticalFlow $flow): array {
		$plan = $this->gate->call($flow->tool, $flow->arguments())['body'];
		// A refusal asked with arguments that no longer plan anything (a consumed target) would prove nothing.
		$this->test->assertTrue($plan->result->structuredContent->requiresConfirmation ?? false,
			$flow->tool . ': the arguments of a refusal step must still plan a write: ' . json_encode($plan) . json_encode($this->gate->logged()));
		return $flow->confirmed($plan);
	}

	/**
	 * @param string $name step name
	 * @param string $reason why it does not apply to this flow
	 * @return void
	 */
	private function skip(string $name, string $reason): void {
		$this->test->assertNotSame('', $reason, 'step ' . $name . ' needs a reason when it does not apply');
		$this->steps[] = ['step' => $name, 'skipped' => $reason];
	}
}
