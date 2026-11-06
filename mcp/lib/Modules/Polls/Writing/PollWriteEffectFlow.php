<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Writing;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Polls\Contracts\PollWriteNative;
use OCA\Mcp\Modules\Polls\Contracts\PollWriteStale;
use OCA\Mcp\Modules\Polls\PollsRefusal;
use OCA\Mcp\Modules\Polls\Contracts\PollMutation;

/** Confirmed effect lifecycle, final native guard and authorized readback; no preview flag. */
final class PollWriteEffectFlow {
	/**
	 * @param PollWriteNative $native Guarded fresh native boundary.
	 * @param PollWritePlan $plans Stateless complete plans.
	 * @return void
	 */
	public function __construct(private PollWriteNative $native, private PollWritePlan $plans) {
	}

	/**
	 * @param array<string,mixed> $r Validated tool-specific request.
	 * @param array<string,mixed> $args Original confirmation and plan state.
	 * @param string $uid Actor.
	 * @return array<string,mixed> Receipt based on a fresh authorized readback.
	 * @throws ToolFailure If a pre-effect guard refuses the actor.
	 * @throws PlanChanged If the approved snapshot changed before the sink.
	 * @throws \InvalidArgumentException If confirmation state or a verb rule is invalid.
	 */
	public function execute(array $r, array $args, string $uid): array {
		if (($args['confirm'] ?? null) !== true) {
			throw PollsRefusal::unavailable();
		}
		PlanState::require($args);
		$attempted = false;
		$result = null;
		$error = null;
		try {
			$this->native->open($uid, PollMutation::from($r['tool'])->operation());
			$before = $this->native->snapshot($uid, $r);
			$plan = $this->plans->of($uid, $r, $before);
			if (!PlanState::matches($plan['plan_state'], $args)) {
				throw new PlanChanged($plan);
			}
			if ($plan['noOp']) {
				$result = PollWriteReceipt::of('applied', false, $r, $before, true);
			} else {
				$writeError = null;
				try {
					$this->native->mutate(
						$uid,
						$r,
						$before,
						static function () use (&$attempted): void {
							$attempted = true;
						}
					);
				} catch (\Throwable $failure) {
					$writeError = $failure;
				}
				if (!$attempted) {
					if ($writeError instanceof PollWriteStale) {
						throw new PlanChanged($this->plans->of($uid, $r, $this->native->snapshot($uid, $r)));
					}
					throw $writeError ?? PollsRefusal::unavailable();
				}
				// Even a throw after an event or email must be followed by a new authorized observation.
				try {
					$now = $this->native->snapshot($uid, $r);
					$result = PollWriteReceipt::of($this->plans->status($r, $before, $now), true, $r, $now);
				} catch (\Throwable) {
					$result = PollWriteReceipt::of('uncertain', true);
				}
			}
		} catch (\Throwable $failure) {
			$error = $failure;
		} finally {
			try {
				$this->native->close();
			} catch (\Throwable $failure) {
				if ($attempted) {
					$result = PollWriteReceipt::of('uncertain', true);
					$error = null;
				} else {
					$error = PollsRefusal::unavailable();
				}
			}
		}
		if ($error !== null) {
			if ($attempted) {
				return PollWriteReceipt::of('uncertain', true);
			}
			if ($error instanceof ToolFailure || $error instanceof \InvalidArgumentException || $error instanceof PlanChanged) {
				throw $error;
			}
			throw PollsRefusal::unavailable();
		}
		return $result ?? throw PollsRefusal::unavailable();
	}
}
