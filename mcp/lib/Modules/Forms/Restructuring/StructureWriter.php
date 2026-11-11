<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Restructuring;

use OCA\Mcp\Modules\Forms\Editing\EditContext;
use OCA\Mcp\Modules\Forms\Integration\CreateNativeThrottle;
use OCA\Mcp\Modules\Forms\Integration\EditNativeFactory;
use OCA\Mcp\Modules\Forms\Integration\StructureState;
use OCA\Mcp\Modules\Forms\Presentation\StructureMessages;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;

/**
 * Performs the single native call of a confirmed structure operation and builds its receipt from a fresh readback.
 *
 * Right before the call it renews the admission, re-reads the whole form and recomputes the plan_state; any difference is a
 * PlanChanged without mutation. After the attempt there is no retry, rollback or second call.
 */
class StructureWriter {
	/**
	 * @param EditNativeFactory $factory admission and native graph
	 * @param StructureState $states fresh snapshots
	 * @param StructurePlan $plans plan_state recomputation
	 * @param CreateNativeThrottle $throttle native brute-force throttling around the call
	 */
	public function __construct(private EditNativeFactory $factory, private StructureState $states, private StructurePlan $plans,
		private CreateNativeThrottle $throttle) {
	}

	/**
	 * @param StructureOperation $op operation
	 * @param EditContext $c admitted native graph
	 * @param array<string,mixed> $r validated request
	 * @param array<string,mixed> $s snapshot the confirmed plan was built on
	 * @param array<string,mixed> $e predicted effect
	 * @param array<string,mixed> $plan confirmed plan
	 * @param string $uid actor
	 * @return array<string,mixed> receipt
	 * @throws PlanChanged|ToolFailure|ArgumentValidationException when refused before the attempt
	 */
	public function write(StructureOperation $op, EditContext $c, array $r, array $s, array $e, array $plan, string $uid): array {
		if ($e['noop']) {
			return ['status' => 'not_attempted', 'nativeCallAttempted' => false, 'reason' => 'no_change', 'notes' => StructureMessages::planNotes()];
		}
		$attempted = false;
		$t0 = 0;
		$response = null;
		try {
			$response = $this->throttle->invoke(
				static fn (): mixed => $op->call($c, $r, $e),
				function () use ($op, $c, $r, $uid, $plan, &$attempted, &$t0): void {
					$this->factory->assertAllowed($uid, $c, [StructureMessages::class, 'unsupportedVersion']);
					$fresh = $this->states->read($c, $r['form_id'], $uid);
					$current = $this->plans->of($op, $r, $c, $fresh, $op->effect($c, $r, $fresh), $uid, true);
					if (!hash_equals($plan[PlanState::ARGUMENT], $current[PlanState::ARGUMENT])) {
						throw new PlanChanged($current);
					}
					$t0 = time();
					$attempted = true;
				});
		} catch (\Throwable $error) {
			if (!$attempted) {
				if ($error instanceof PlanChanged || $error instanceof ToolFailure || $error instanceof ArgumentValidationException) {
					throw $error;
				}
				throw new ToolFailure(StructureMessages::refused(), 0, $error);
			}
		}
		$t1 = time();
		try {
			$this->factory->assertAllowed($uid, $c, [StructureMessages::class, 'unsupportedVersion']);
			$after = $this->states->read($c, $r['form_id'], $uid, false);
			return StructureReceipt::observed($op, $c, $r, $s, $e, $after, $response, $uid, $t0, $t1);
		} catch (\Throwable) {
			return ['status' => 'uncertain', 'nativeCallAttempted' => true, 'message' => StructureMessages::refused(), 'notes' => StructureMessages::receiptNotes()];
		}
	}
}
