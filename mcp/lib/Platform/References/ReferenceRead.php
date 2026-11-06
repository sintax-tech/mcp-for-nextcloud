<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Platform\References;

use InvalidArgumentException;
use Throwable;

/** Internal read outcome: a visible item, absence, or the original lookup cause; never an MCP payload. */
final readonly class ReferenceRead {
	/**
	 * @param array<string,string|int>|null $item readable scalar fields, or null when absent/refused
	 * @param Throwable|null $failure original lookup failure, retained only for the caller's existing log
	 * @throws InvalidArgumentException when both success and failure are supplied
	 */
	public function __construct(public ?array $item = null, public ?Throwable $failure = null) {
		if ($item !== null && $failure !== null) {
			throw new InvalidArgumentException('A reference read cannot contain both an item and a failure.');
		}
	}
}
