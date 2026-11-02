<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Grants\Commands;

use Closure;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\PolicyConflict;
use OCA\Mcp\Access\Policy\PolicyEventPayload;
use OCA\Mcp\Access\Policy\PolicyUnavailable;

/** Public GrantCommands contract inherited by the historical policy facade. */
interface GrantCommandsContract {
	/**
	 * Changes individual eligibility and preserves its historical revocation scope.
	 *
	 * @param string $uid Nextcloud user id
	 * @param bool $enabled new eligibility; false also revokes the user's connections
	 * @throws \InvalidArgumentException for a user the server does not know
	 * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain ($uncertain = true)
	 */
	public function setEligible(string $uid, bool $enabled): void;

	/**
	 * Changes the personal connection flag and preserves disconnect cuts.
	 *
	 * @param string $uid Nextcloud user id
	 * @param bool $enabled new personal connection state; false (disconnect) also revokes every credential of the
	 *     user, repeated or not
	 * @throws \InvalidArgumentException for a user the server does not know
	 * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain ($uncertain = true)
	 */
	public function setConnected(string $uid, bool $enabled): void;

	/**
	 * Stores an explicit individual allow or deny, which differs from an absent override.
	 *
	 * @param string $uid Nextcloud user id
	 * @param string $module catalog module
	 * @param string $operation catalog operation of that module
	 * @param bool $enabled explicit allow (true) or deny (false)
	 * @throws \InvalidArgumentException for an unknown user, module or operation
	 * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain ($uncertain = true)
	 */
	public function setGrant(string $uid, string $module, string $operation, bool $enabled): void;

	/**
	 * One administrative command over many user cells, as one transaction: every cell is written or none is. Cells
	 * whose raw value is already the requested one are no-ops; a command where every cell is a no-op commits nothing
	 * and leaves the revision as it was. Otherwise it commits one receipt (undoable for RECEIPT_TTL seconds by the
	 * same administrator) and one audit event, in the same transaction.
	 *
	 * @param string $expectedRevision the revision the administrator's page was built from
	 * @param string $actor uid of the administrator who sent the command
	 * @param string $action PolicyEventPayload::GRANT_ACTIONS member naming the route
	 * @param list<array{uid:string, key:string, value:?string}> $cells key from PolicyEventPayload::userKey(); value
	 *     '1' (allow), '0' (deny) or null (inherit: remove the override). An exact duplicate is dropped; the same cell
	 *     with two values is refused.
	 * @param (Closure(string): (list<array{groupId:string, gen:string}>|null))|null $inheritance
	 *     GrantPolicy::inheritance(), for a cell that removes an individual Allow of eligibility (see writeCell());
	 *     null cuts on every such removal, as before
	 * @return array{revision:string, changed:int, receiptId:?string} committed revision, changed cell count, receipt
	 * @throws \InvalidArgumentException for an invalid revision, cell, unknown user, or more than MAX_UIDS users or
	 *     MAX_CELLS cells; nothing is written
	 * @throws PolicyConflict when the expected revision is stale
	 * @throws PolicyUnavailable when the policy storage fails, the outcome is uncertain ($uncertain = true), or an
	 *     individual Allow is removed while what the user inherits is unknown (nothing written, nothing cut)
	 */
	public function applyCells(string $expectedRevision, string $actor, string $action, array $cells, ?Closure $inheritance = null): array;
}
