<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools;

use OCA\Mcp\Mcp\Confirmation\PlanText;

/** Recognizes the existing inert text formats, including reversible quotes needed by complete creation plans. */
final class PlanPayloadMatch {
	/** Checks for an exact approved escaping representation, never raw submitted text.
	 * @param string $rendered Rendered body or line.
	 * @param string $payload Original hostile value.
	 * @return bool Whether a complete inert representation is present.
	 */
	public static function contains(string $rendered, string $payload): bool {
		return str_contains($rendered, PlanText::inline($payload)) || str_contains($rendered, PlanText::quote($payload)) || str_contains($rendered, PlanText::quoted($payload));
	}
}
