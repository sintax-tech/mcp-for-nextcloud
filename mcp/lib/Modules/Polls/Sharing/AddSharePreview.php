<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Sharing;

use OCA\Mcp\Modules\Polls\Writing\PollWritePreviewFlow;
use OCA\Mcp\Modules\Polls\Writing\PollWriteRequest;
use OCA\Mcp\Modules\Polls\Contracts\PollMutation;

/** Effect-free preview of polls_add_share; shared guards remain in the mutation flow. */
final class AddSharePreview {
	/**
	 * @param PollWritePreviewFlow $flow Fresh guarded mutation flow.
	 * @return void
	 */
	public function __construct(private PollWritePreviewFlow $flow) {
	}

	/**
	 * @param array<string,mixed> $arguments Tool arguments.
	 * @param string $uid Authenticated actor.
	 * @return array<string,mixed> Complete signed plan.
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure If admission or native observation fails.
	 * @throws \OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException If the request is invalid.
	 * @throws \OCA\Mcp\Mcp\Confirmation\PlanChanged If the confirmed snapshot changed.
	 */
	public function plan(array $arguments, string $uid): array {
		$request = PollWriteRequest::of(PollMutation::AddShare->value, $arguments);
		return $this->flow->plan($request, $uid);
	}
}
