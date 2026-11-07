<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Receipts;

use Closure;
use OCA\Mcp\Access\Policy\PolicyConflict;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\ReceiptExpired;
use OCA\Mcp\Access\Policy\ReceiptNotFound;

/** Public receipt undo contract inherited by the historical policy facade. */
interface ReceiptRestorerContract {
	/**
	 * Undoes the cells of one receipt, as a new transaction and revision: each cell gets its exact raw value from
	 * before the command back, including absence. Tokens and epochs revoked by the command are not restored, but a
	 * cell that loses eligibility again revokes like any other change. Only the receipt's own administrator may undo
	 * it, only within RECEIPT_TTL seconds and only while the policy is still at the revision that command committed:
	 * any later change, to these cells or any other, is a conflict that touches nothing.
	 *
	 * @param string $receiptId id returned by applyCells()
	 * @param string $expectedRevision the revision the receipt's command committed
	 * @param string $actor uid of the administrator asking
	 * @return array{revision:string, changed:int} committed revision and restored cell count
	 * @throws \InvalidArgumentException for an invalid revision
	 * @throws ReceiptNotFound for an unknown or invented receipt, or one created by another administrator
	 * @throws ReceiptExpired for the caller's own receipt past RECEIPT_TTL
	 * @param (Closure(string): (list<array{groupId:string, gen:string}>|null))|null $inheritance as for applyCells(),
	 *     for an undo that restores the absence of an individual Allow of eligibility
	 * @throws PolicyConflict when the policy changed since the receipt's commit, or one of its users is gone
	 * @throws PolicyUnavailable when the policy storage fails, the outcome is uncertain ($uncertain = true), or an
	 *     individual Allow is removed while what the user inherits is unknown (nothing written, nothing cut)
	 */
	public function restoreReceipt(string $receiptId, string $expectedRevision, string $actor, ?Closure $inheritance = null): array;

	/**
	 * The users a receipt names, for the state of a refused undo; the same ownership and validity rules as
	 * restoreReceipt(), but expiry does not matter for showing the current rows.
	 *
	 * @return list<string>|null its distinct uids in order, or null for an unknown, foreign or unreadable receipt
	 */
	public function receiptUsers(string $receiptId, string $actor): ?array;
}
