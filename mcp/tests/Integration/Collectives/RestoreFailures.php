<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Collectives;

use RuntimeException;
use Throwable;

/** Every step of a cell restoration that failed, raised once after all of them were tried. */
final class RestoreFailures extends RuntimeException {
	/** @param list<array{step:string, error:Throwable}> $failures each failed step and what it threw, in order */
	public function __construct(public readonly array $failures) {
		parent::__construct('The cell could not be fully restored: ' . implode('; ', array_map(
			static fn (array $failure): string => $failure['step'] . ': ' . $failure['error']->getMessage(), $failures)), 0, $failures[0]['error'] ?? null);
	}
}
