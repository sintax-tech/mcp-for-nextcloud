<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Reading;

use OCA\Mcp\Mcp\Confirmation\PlanState;

/** Orders complete bounded headers and keys their state without exposing account identifiers. */
final class SubmissionOrder {
	/** Applies a total order before local pagination; native timestamp order alone has ties.
	 * @param array $headers Private complete admitted header universe.
	 * @return array Totally ordered headers.
	 */
	public static function sorted(array $headers): array {
		usort($headers, static fn (array $a, array $b): int => [$b['timestamp'], $b['id']] <=> [$a['timestamp'], $a['id']]);
		return $headers;
	}
	/** Hashes only headers, with internal strings encoded as exact bytes before JSON/MAC.
	 * @param PlanState $mac Instance secret MAC.
	 * @param string $uid Actor bytes.
	 * @param array $headers Ordered complete header universe.
	 * @return string Keyed state, not a public respondent pseudonym.
	 */
	public static function digest(PlanState $mac, string $uid, array $headers): string {
		$tuples = array_map(static fn (array $row): array => [$row['id'], $row['formId'], $row['timestamp'], strlen($row['userId']), base64_encode($row['userId'])], $headers);
		return $mac->of('forms-submission-headers-v1', base64_encode($uid), $tuples);
	}
}
