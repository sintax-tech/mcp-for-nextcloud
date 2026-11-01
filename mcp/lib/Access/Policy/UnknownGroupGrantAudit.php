<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy;

/** Compact audit variant for an explicit cleanup, without selection IDs or report fingerprints. */
final class UnknownGroupGrantAudit {
	/**
	 * @param string $actor Authenticated administrator.
	 * @param string $gid Exact group identity.
	 * @param string $revision Revision observed under the policy mutex.
	 * @param list<array{module:string,operation:string}> $selected Removed opaque rows; extra report fields are omitted.
	 * @return array Valid ACTION_GROUP audit payload with the documented unknownCleanup snapshot variant.
	 */
	public static function payload(string $actor, string $gid, string $revision, array $selected): array {
		$pairs = array_map(static fn (array $row): array => ['module' => $row['module'], 'operation' => $row['operation']], $selected);
		usort($pairs, static fn (array $a, array $b): int => strcmp($a['module'], $b['module']) ?: strcmp($a['operation'], $b['operation']));
		return PolicyEventPayload::audit($actor, PolicyEventPayload::ACTION_GROUP, $revision, PolicyWriter::next($revision), null, null,
			[['gid' => $gid, 'key' => PolicyEventPayload::GROUP_KEY,
				'before' => json_encode(['unknownCleanup' => $pairs], JSON_THROW_ON_ERROR),
				'after' => json_encode(['unknownCleanup' => []], JSON_THROW_ON_ERROR)]]);
	}
}
