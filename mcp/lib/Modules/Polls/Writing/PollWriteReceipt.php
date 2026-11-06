<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Writing;

use OCA\Mcp\Modules\Polls\Presentation\PollsWriteMessages;

/** Fresh authorized observations only; never includes stale requested fields on failure. */
final class PollWriteReceipt {
	/**
	 * @param string $status Observation classification.
	 * @param bool $attempted Sink attempted.
	 * @param array<string,mixed>|null $r Authorized request.
	 * @param array<string,mixed>|null $s Newly authorized observation.
	 * @param bool $noop No sink needed.
	 * @return array<string,mixed> Whitelist receipt; no stale snapshot on failure.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public static function of(string $status, bool $attempted, ?array $r = null, ?array $s = null, bool $noop = false): array {
		$out = [
			'status' => $status,
			'nativeCallAttempted' => $attempted,
			'noOp' => $noop,
			'untrustedPollsData' => true,
			'notes' => PollsWriteMessages::notes(),
		];
		if ($r !== null && $s !== null) {
			$out['requested'] = $r;
			$out['observed'] = array_intersect_key($s, array_flip(['poll', 'configuration', 'options', 'shares']));
		}
		return $out;
	}
}
