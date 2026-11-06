<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Editing;

use OCA\Mcp\Modules\Polls\Writing\PollWriteRequest;
use OCA\Mcp\Modules\Polls\Presentation\PollsWriteMessages;

/** Approved configuration whitelist and desired-state transitions, never toggles in public input. */
final class PollConfiguration {
	/** @param array<string,mixed> $r Request. @param array<string,mixed> $s Snapshot. @return array<string,mixed> Before/after effect. */
	public static function effect(array $r, array $s): array {
		if (isset($r['changes'])) {
			$before = [];
			foreach ($r['changes'] as $key => $value) {
				if (!array_key_exists($key, $s['configuration'])) { PollWriteRequest::invalid('changes'); }
				$before[$key] = $s['configuration'][$key];
			}
			return ['before' => $before, 'after' => $r['changes'], 'noop' => $before === $r['changes'], 'notes' => [PollsWriteMessages::configuration()]];
		}
		$before = ['expire' => $s['poll']['expire'], 'archived' => $s['poll']['archived']];
		$after = match ($r['state']) {
			'closed' => ['closed' => true], 'reopened' => ['expire' => 0],
			'archived' => ['archived' => true], 'unarchived' => ['archived' => false],
		};
		return ['before' => $before, 'after' => $after, 'noop' => self::stateMatches($r['state'], $s), 'notes' => [PollsWriteMessages::state()]];
	}
	/** @param string $state Desired state. @param array<string,mixed> $s Fresh snapshot. @return bool State matches, including expiration removal on reopen. */
	private static function stateMatches(string $state, array $s): bool {
		return match ($state) {
			'closed' => $s['poll']['expire'] > 0 && $s['poll']['expire'] < time(),
			'reopened' => $s['poll']['expire'] === 0,
			'archived' => $s['poll']['archived'], 'unarchived' => !$s['poll']['archived'],
		};
	}
	/** @param array<string,mixed> $r Request. @param array<string,mixed> $before Initial state. @param array<string,mixed> $now Fresh state. @return bool Requested fields observed. */
	public static function matches(array $r, array $before, array $now): bool {
		if (isset($r['state'])) { return self::stateMatches($r['state'], $now); }
		foreach (['type', 'owner', 'access', 'anonymous'] as $key) {
			if (($before['poll'][$key] ?? null) !== ($now['poll'][$key] ?? null)) { return false; }
		}
		foreach ($r['changes'] as $key => $value) {
			if (!array_key_exists($key, $now['configuration']) || $now['configuration'][$key] !== $value) { return false; }
		}
		return true;
	}
}
