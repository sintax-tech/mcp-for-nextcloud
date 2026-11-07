<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Options;

use OCA\Mcp\Modules\Polls\Writing\PollWriteEffectFlow;
use OCA\Mcp\Modules\Polls\Writing\PollWriteRequest;
use OCA\Mcp\Modules\Polls\Contracts\PollMutation;

/** Confirmed effect of polls_restore_option; shared guards remain in the mutation flow. */
final class RestoreOptionHandler {
	/**
	 * @param PollWriteEffectFlow $flow Fresh guarded mutation flow.
	 * @return void
	 */
	public function __construct(private PollWriteEffectFlow $flow) {
	}

	/**
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @param string $uid Authenticated actor.
	 * @return array<string,mixed> Honest observed receipt.
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure If admission or native observation fails.
	 * @throws \OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException If the request is invalid.
	 * @throws \OCA\Mcp\Mcp\Confirmation\PlanChanged If the confirmed snapshot changed.
	 */
	public function handle(array $arguments, string $uid): array {
		$request = PollWriteRequest::of(PollMutation::RestoreOption->value, $arguments);
		return $this->flow->execute($request, $arguments, $uid);
	}
}
