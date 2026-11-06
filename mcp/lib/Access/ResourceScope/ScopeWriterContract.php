<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\ResourceScope;

use OCA\Mcp\Access\Policy\PolicyConflict;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\ScopeInactive;
use OCA\Mcp\Access\Policy\ScopeItemsInaccessible;
use OCA\Mcp\Access\Policy\ScopeTooLarge;

/** Public resource-scope save contract inherited by the historical policy facade. */
interface ScopeWriterContract {
	/**
	 * Saves the resource scope of one module for one user (contracts B.4): null makes the module unrestricted, a list
	 * restricts it to exactly those resources, and an empty list restricts it to nothing. Same transaction as every
	 * policy command: expected revision, global mutex, CAS, one receipt (undoable for RECEIPT_TTL seconds by the same
	 * administrator) and one audit event; the scope rows carry policy_rev = the revision this commit produces. It
	 * touches no credential, epoch or generation: a new scope applies from the user's next operation.
	 *
	 * The module's activation barrier is checked first, before anything else is read: while it is closed the command
	 * is refused with no write, no receipt and no revision. Outside the mutex the user must exist and every resource
	 * must be reachable natively by that user (NativeResourceEnumerator, never the MCP view); duplicates are dropped
	 * and the limit applies to the distinct set. Saving the scope already stored commits nothing.
	 *
	 * @param string $expectedRevision the revision the administrator's page was built from
	 * @param string $actor uid of the administrator who sent the command
	 * @param string $uid target user
	 * @param string $module one of ResourceScopeMapper::MODULES
	 * @param list<string>|null $items canonical decimal ids, or null for unrestricted
	 * @return array{revision:string, changed:bool, receiptId:?string}
	 * @throws ScopeInactive while the module's activation barrier is closed
	 * @throws \InvalidArgumentException for an invalid actor, module, revision, id, or an unknown user
	 * @throws ScopeTooLarge for more than ResourceScopeMapper::MAX_ITEMS distinct resources
	 * @throws ScopeItemsInaccessible for a resource the user cannot reach natively
	 * @throws PolicyConflict when the expected revision is stale
	 * @throws PolicyUnavailable when the policy storage fails, or the outcome is uncertain ($uncertain = true)
	 */
	public function setResourceScope(string $expectedRevision, string $actor, string $uid, string $module, ?array $items): array;
}
