<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\Preparation;

/** The JSON object inside one MCP text block. */
final class ResultJson {
	/** Decoding only. */
	private function __construct() {
	}

	/**
	 * @param array<string, mixed> $result an MCP result
	 * @return array<string, mixed> the first JSON object, or an empty array
	 */
	public static function object(array $result): array {
		foreach ((array)($result['content'] ?? []) as $block) {
			if (!is_array($block)) {
				continue;
			}
			$decoded = json_decode((string)($block['text'] ?? ''), true);
			if (is_array($decoded) && !array_is_list($decoded)) {
				return $decoded;
			}
		}
		return [];
	}
}
