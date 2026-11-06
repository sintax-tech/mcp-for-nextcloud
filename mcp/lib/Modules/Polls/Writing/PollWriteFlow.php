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
use OCA\Mcp\Modules\Polls\Presentation\PollsWriteSchema;
use OCA\Mcp\Modules\Polls\Presentation\PollsWriteMessages;

/** One attempt boundary shared by the ten handlers; readbacks never reuse sink return values. */
final class PollWriteFlow {
	/** @param PollWriteNative $native Guarded fresh native boundary. @param PollWritePlan $plans Stateless complete plans. */
	public function __construct(private PollWriteNative $native, private PollWritePlan $plans) {}
	/** @param string $tool Exact tool. @param array<string,mixed> $args Raw arguments. @param string $uid Actor. @param bool $preview Plan only. @return array<string,mixed> Safe plan or receipt. */
	public function run(string $tool, array $args, string $uid, bool $preview): array {
		$r = PollWriteRequest::of($tool, $args);
		if (!$preview) {
			if (($args['confirm'] ?? null) !== true) { throw PollsRefusal::unavailable(); }
			PlanState::require($args);
		}
		$attempted = false; $result = null; $error = null;
		try {
			$this->native->open($uid, PollsWriteSchema::OPERATIONS[$tool]);
			$before = $this->native->snapshot($uid, $r);
			$plan = $this->plans->of($uid, $r, $before);
			if ($preview) { $result = $plan; }
			else {
				if (!PlanState::matches($plan['plan_state'], $args)) { throw new PlanChanged($plan); }
				if ($plan['noOp']) {
					$result = $this->receipt('applied', false, $r, $before, true);
				} else {
					$writeError = null;
					try {
						$this->native->mutate($uid, $r, $before, static function () use (&$attempted): void { $attempted = true; });
					} catch (\Throwable $failure) { $writeError = $failure; }
					if (!$attempted) {
						if ($writeError instanceof PollWriteStale) {
							throw new PlanChanged($this->plans->of($uid, $r, $this->native->snapshot($uid, $r)));
						}
						throw $writeError ?? PollsRefusal::unavailable();
					}
					// Even a throw after an event or email must be followed by a new authorized observation.
					try {
						$now = $this->native->snapshot($uid, $r);
						$result = $this->receipt($this->plans->status($r, $before, $now), true, $r, $now);
					} catch (\Throwable) { $result = $this->receipt('uncertain', true); }
				}
			}
		} catch (\Throwable $failure) { $error = $failure; }
		finally {
			try { $this->native->close(); }
			catch (\Throwable $failure) {
				if ($attempted) { $result = $this->receipt('uncertain', true); $error = null; }
				else { $error = PollsRefusal::unavailable(); }
			}
		}
		if ($error !== null) {
			if ($attempted) { return $this->receipt('uncertain', true); }
			if ($error instanceof ToolFailure || $error instanceof \InvalidArgumentException || $error instanceof PlanChanged) { throw $error; }
			throw PollsRefusal::unavailable();
		}
		return $result ?? throw PollsRefusal::unavailable();
	}
	/** @param string $status Observation classification. @param bool $attempted Sink attempted. @param array<string,mixed>|null $r Authorized request. @param array<string,mixed>|null $s Newly authorized observation. @param bool $noop No sink needed. @return array<string,mixed> Whitelist receipt; no stale snapshot on failure. */
	private function receipt(string $status, bool $attempted, ?array $r = null, ?array $s = null, bool $noop = false): array {
		$out = ['status' => $status, 'nativeCallAttempted' => $attempted, 'noOp' => $noop,
			'untrustedPollsData' => true, 'notes' => PollsWriteMessages::notes()];
		if ($r !== null && $s !== null) {
			$out['requested'] = $r;
			$out['observed'] = array_intersect_key($s, array_flip(['poll', 'configuration', 'options', 'shares']));
		}
		return $out;
	}
}
