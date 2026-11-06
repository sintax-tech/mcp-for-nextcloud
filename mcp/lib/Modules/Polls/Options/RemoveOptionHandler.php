<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Options;

use OCA\Mcp\Modules\Polls\Writing\PollWriteFlow;

/** Confirmed effect of polls_remove_option; shared guards remain in the mutation flow. */
final class RemoveOptionHandler {
	/** @param PollWriteFlow $flow Fresh guarded mutation flow. */
	public function __construct(private PollWriteFlow $flow) {}
	/** @param array<string,mixed> $arguments Tool arguments. @param string $uid Authenticated actor. @return array<string,mixed> Honest observed receipt. */
	public function handle(array $arguments, string $uid): array {
		return $this->flow->run('polls_remove_option', $arguments, $uid, false);
	}
}
