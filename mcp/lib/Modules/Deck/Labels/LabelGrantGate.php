<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Deck\Labels;

use OCA\Mcp\Modules\Deck\Failures\DeckRefusalException;
use OCA\Mcp\Modules\Deck\Presentation\DeckLabelMessages;

/** Rechecks the registered caller's grant immediately before a native effect. */
final class LabelGrantGate {
	/** @param \Closure():bool|null $granted Registered grant reader; null only for native-boundary tests. @return void */
	public static function require(?\Closure $granted): void {
		if ($granted !== null && !$granted()) {
			throw new DeckRefusalException(DeckLabelMessages::creationNeedsGrant([]));
		}
	}
	/** Static gate. */
	private function __construct() {
	}
}
