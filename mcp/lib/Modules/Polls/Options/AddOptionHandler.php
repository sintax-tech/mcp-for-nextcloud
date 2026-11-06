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

/** Confirmed effect of polls_add_option; shared guards remain in the mutation flow. */
final class AddOptionHandler {
	/** @param PollWriteEffectFlow $flow Fresh guarded mutation flow. */
	public function __construct(private PollWriteEffectFlow $flow) {}
	/** @param array<string,mixed> $arguments Tool arguments. @param string $uid Authenticated actor. @return array<string,mixed> Honest observed receipt. */
	public function handle(array $arguments, string $uid): array {
		$request = PollWriteRequest::of(PollMutation::AddOption->value, $arguments);
		return $this->flow->execute($request, $arguments, $uid);
	}
}
