<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Service\Policy\PolicyWriter;

/**
 * The policy failure of the controller effect contracts: the real {@see PolicyWriter} in the state it is left in after
 * a rollback or a cache clear failed (its context abandoned), so every read and write through it raises the real
 * PolicyUnavailable. Nothing is mocked: the controller under test meets the same writer it always uses.
 */
final class UnusableWriter {
	/** Static helper only. */
	private function __construct() {
	}

	/**
	 * Runs $request while the writer is unusable, then makes it usable again so the state after it can be read.
	 *
	 * @template T
	 * @param PolicyWriter $writer the writer of the stores under test
	 * @param \Closure(): T $request one controller request
	 * @return T what the request returned
	 */
	public static function during(PolicyWriter $writer, \Closure $request): mixed {
		$usable = new \ReflectionProperty(PolicyWriter::class, 'usable');
		$usable->setValue($writer, false);
		try {
			return $request();
		} finally {
			$usable->setValue($writer, true);
		}
	}
}
