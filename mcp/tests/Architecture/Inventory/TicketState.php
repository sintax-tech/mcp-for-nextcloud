<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Architecture\Inventory;

use RuntimeException;

/** Portable completion ledger: ticket numbers do not imply execution order. */
final class TicketState {
	/** Integration floor cannot be removed to reactivate an expired exception. */
	public const FLOOR = ['RE-01', 'RE-01b', 'RE-02', 'RE-03', 'RE-04', 'RE-05', 'RE-05b', 'RE-10', 'RE-11', 'RE-12'];

	/** @param list<string> $known Frozen plan IDs. @param list<string> $completed Integrated ticket IDs. */
	public function __construct(public readonly array $known, public readonly array $completed) {
		if ($known === [] || count($known) !== count(array_unique($known)) || count($completed) !== count(array_unique($completed)) || array_diff(self::FLOOR, $completed) !== [] || array_diff($completed, $known) !== []) { throw new RuntimeException('Invalid or regressed ticket completion ledger'); }
	}

	/** @param string $directory Architecture data directory. @return self Validated state. */
	public static function load(string $directory): self { return new self(AppTree::json($directory . '/known-tickets.json'), AppTree::json($directory . '/completed-tickets.json')); }

	/** @param string $ticket Expiration ID. @return void @throws RuntimeException Unknown or completed ticket. */
	public function pending(string $ticket): void {
		if (!in_array($ticket, $this->known, true) || in_array($ticket, $this->completed, true)) { throw new RuntimeException('Unknown/completed expiration: ' . $ticket); }
	}
}
