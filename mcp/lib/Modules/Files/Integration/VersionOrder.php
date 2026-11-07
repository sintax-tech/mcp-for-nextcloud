<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Integration;

/** Orders native versions independently of backend enumeration, before the caller applies its limit. */
final class VersionOrder {
	/** Static ordering only. */
	private function __construct() {
	}

	/**
	 * @param list<object> $versions native versions exposing getTimestamp() and getRevisionId()
	 * @return list<object> timestamp descending, then natural revision descending with a bytewise tie-break
	 */
	public static function newestFirst(array $versions): array {
		usort($versions, static function (object $left, object $right): int {
			$time = $right->getTimestamp() <=> $left->getTimestamp();
			$leftRevision = (string)$left->getRevisionId();
			$rightRevision = (string)$right->getRevisionId();
			return $time ?: (strnatcmp($rightRevision, $leftRevision) ?: strcmp($rightRevision, $leftRevision));
		});
		return $versions;
	}
}
