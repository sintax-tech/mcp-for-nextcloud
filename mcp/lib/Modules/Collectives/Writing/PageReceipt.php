<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Writing;

use OCA\Mcp\Modules\Collectives\Presentation\CollectivesMessages;

/**
 * The receipt of a confirmed Collectives write, built only from what a fresh, authorized read observed: `applied`
 * when every requested effect is seen, `partial` when only some are, `uncertain` when the read was not possible or
 * the native call failed. The native answer alone never makes a write `applied`, and nothing is retried.
 */
final class PageReceipt {
	/** Static helper only; never instantiated. */
	private function __construct() {
	}

	/**
	 * @param array<string, mixed> $requested the effect asked for
	 * @param array<string, mixed>|null $observed the same keys as read back, null when the read failed
	 * @param array<string, mixed> $extra what else the receipt carries (ids, page)
	 * @return array<string, mixed> the receipt
	 */
	public static function of(array $requested, ?array $observed, array $extra = []): array {
		if ($observed === null) {
			return ['status' => 'uncertain', 'nativeCallAttempted' => true, 'requested' => $requested, 'message' => CollectivesMessages::uncertain()] + $extra;
		}
		$matched = array_filter($requested, static fn (mixed $value, string $key): bool => ($observed[$key] ?? null) === $value, ARRAY_FILTER_USE_BOTH);
		$status = count($matched) === count($requested) ? 'applied' : ($matched === [] ? 'uncertain' : 'partial');
		return ['status' => $status, 'nativeCallAttempted' => true, 'requested' => $requested, 'observed' => $observed,
			'message' => match ($status) {
				'applied' => CollectivesMessages::applied(),
				'partial' => CollectivesMessages::partial(),
				default => CollectivesMessages::uncertain(),
			}] + $extra;
	}
}
