<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Presentation;

use OCA\Mcp\Mcp\Confirmation\PlanText;

/** Renders complete sanitized plans without interpreting poll content as instructions. */
final class PollsWritePlanRenderer {
	/**
	 * @param string $tool Tool name.
	 * @param array<string,mixed> $plan Complete safe projection.
	 * @return string|null New mutation plans only.
	 */
	public function render(string $tool, array $plan): ?string {
		if (!isset(PollsWriteSchema::OPERATIONS[$tool])) {
			return null;
		}
		return implode("\n", $this->lines($plan));
	}

	/**
	 * @param array<string|int,mixed> $value Complete plan.
	 * @param string $prefix Field path.
	 * @return list<string> Inert complete lines, without truncation.
	 */
	private function lines(array $value, string $prefix = ''): array {
		$lines = [];
		foreach ($value as $key => $item) {
			$path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
			if (is_array($item) && $item !== []) {
				array_push($lines, ...$this->lines($item, $path));
			} else {
				$lines[] = '- ' . PlanText::inline($path,
					65536) . ': ' . PlanText::inline(is_string($item)
					? $item
					: json_encode($item,
					JSON_THROW_ON_ERROR),
					65536);
			}
		}
		return $lines;
	}
}
