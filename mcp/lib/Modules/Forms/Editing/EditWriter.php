<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Editing;

use OCA\Mcp\Modules\Forms\Presentation\EditMessages;
use OCA\Mcp\Modules\Forms\Integration\EditNativeFactory;
use OCA\Mcp\Modules\Forms\Integration\EditState;

use OCA\Mcp\Modules\Forms\Integration\CreateNativeThrottle;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** Makes exactly one native PATCH, revalidating after throttle and observing active actor-authorized state even after failure. */
class EditWriter {
	/** Receives focused boundary/readback collaborators and the existing native throttle bridge.
	 * @param EditNativeFactory $factory Renewed policy/session/app admission.
	 * @param EditState $states Active authorized structure.
	 * @param EditPlan $plans Complete authenticated plans.
	 * @param CreateNativeThrottle $throttle Official throttle sequence.
	 */
	public function __construct(private EditNativeFactory $factory, private EditState $states, private EditPlan $plans, private CreateNativeThrottle $throttle) { }
	/** Revalidates immediately before controller entry, then returns only new observations.
	 * @param EditContext $c Exact per-operation native graph.
	 * @param array<string,mixed> $r Closed request.
	 * @param array<string,mixed> $s Approved current snapshot.
	 * @param array<string,mixed> $e Approved expected effect.
	 * @param array<string,mixed> $plan Approved public plan.
	 * @param string $uid Actor.
	 * @return array<string,mixed> Applied/partial/uncertain receipt without retry/compensation.
	 */
	public function write(EditContext $c, array $r, array $s, array $e, array $plan, string $uid): array {
		if ($e['patch'] === []) { return ['status' => 'not_attempted', 'nativeCallAttempted' => false, 'reason' => 'no_change', 'notes' => EditMessages::notes()]; }
		$attempted = false; $valid = false; $t0 = 0;
		try {
			$response = $this->throttle->invoke(
				static fn () => $r['option'] ? $c->controller->updateOption($r['form_id'], $r['question_id'], $r['option_id'], $e['patch']) : $c->controller->updateQuestion($r['form_id'], $r['question_id'], $e['patch']),
				function () use ($c, $r, $uid, $plan, &$attempted, &$t0): void {
					$this->factory->assertAllowed($uid, $c);
					$fresh = $this->states->read($c, $r, $uid); $effect = $this->states->effect($c, $r, $fresh);
					$current = $this->plans->of($r, $c, $fresh, $effect, $uid, true);
					if (!hash_equals($plan[PlanState::ARGUMENT], $current[PlanState::ARGUMENT])) { throw new PlanChanged($current); }
					$t0 = time(); $attempted = true;
				});
			$valid = $response instanceof \OCP\AppFramework\Http\DataResponse && $response->getStatus() === 200 && $response->getData() === ($r['option_id'] ?? $r['question_id']);
		} catch (\Throwable $error) {
			if (!$attempted) {
				if ($error instanceof PlanChanged || $error instanceof ToolFailure) { throw $error; }
				throw new ToolFailure(EditMessages::refused(), 0, $error);
			}
		}
		$t1 = time();
		try {
			$this->factory->assertAllowed($uid, $c);
			$after = $this->states->read($c, $r, $uid, false);
			return EditReceipt::observed($r, $s, $e, $after, $uid, $t0, $t1, $valid);
		} catch (\Throwable) {
			return ['status' => 'uncertain', 'nativeCallAttempted' => true, 'message' => EditMessages::refused(), 'notes' => EditMessages::receiptNotes()];
		}
	}
}
