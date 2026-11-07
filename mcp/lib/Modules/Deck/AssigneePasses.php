<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck;

use OCA\Mcp\Modules\Deck\Integration\DeckErrors;

/**
 * Runs the assignments of one tool call in two passes (issue #26).
 *
 * The first pass is the direct Deck call of 1.2.5 for every assignee, so each assignee whose push can be delivered
 * gets it inline, as before. An assignment that failed with an error no refusal explains, and that the guarded
 * write found unsaved, was stopped by the assignee's push delivery, which Deck runs before it stores the assignment.
 * Those are retried in a second pass, after all the others, with the push deferred past the insert. A push that
 * fails in that pass leaves Nextcloud's deferral and the Notifications queue unable to send anything else in the
 * request, so it must never come before a delivery that would have worked.
 */
final class AssigneePasses {
	/**
	 * Runs each assignment directly, then retries the ones its push delivery stopped.
	 *
	 * @template K of array-key
	 * @param array<K, \Closure(bool): mixed> $assignments One guarded assignment per key, called with `$deferDelivery`
	 *     false first and true for the retry; it returns the stored assignment or throws.
	 * @return array<K, mixed> The stored assignment, or the final \Throwable, per key: first-pass outcomes in the
	 *     given order, then the retries.
	 */
	public static function run(array $assignments): array {
		$outcomes = [];
		$retries = [];
		foreach ($assignments as $key => $assign) {
			try {
				$outcomes[$key] = $assign(false);
			} catch (\Throwable $e) {
				if (DeckErrors::unexplained($e)) {
					$retries[] = $key;
				} else {
					$outcomes[$key] = $e;
				}
			}
		}
		foreach ($retries as $key) {
			try {
				$outcomes[$key] = $assignments[$key](true);
			} catch (\Throwable $e) {
				$outcomes[$key] = $e;
			}
		}

		return $outcomes;
	}
}
