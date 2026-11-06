<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Presentation;

/** Full bounded JSON plan: nested filters never disappear in the generic renderer's depth limit. */
final class SchemaPlanRenderer {
	/**
	 * @param string $tool Published tool.
	 * @return bool Whether this renderer owns it.
	 */
	public static function supports(string $tool): bool {
		return in_array($tool, ['tables_create_table', 'tables_update_table', 'tables_create_column', 'tables_update_column', 'tables_create_view', 'tables_update_view', 'tables_share_node', 'tables_unshare_node'], true);
	}
	/**
	 * @param array<string,mixed> $plan Authorized bounded plan.
	 * @return string Complete inert plan without the HMAC.
	 */
	public static function render(array $plan): string {
		unset($plan['plan_state']);
		$json = json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
		preg_match_all('/~+/', $json, $runs);
		$length = 3;
		foreach ($runs[0] as $run) $length = max($length, strlen($run) + 1);
		$fence = str_repeat('~', $length);
		return $fence . "json\n" . $json . "\n" . $fence;
	}
}
