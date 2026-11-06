<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Grants\Groups;

use Closure;
use OCA\Mcp\Access\Policy\GroupSourceUntrusted;
use OCA\Mcp\Access\Policy\PolicyConflict;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Access\Policy\ReceiptExpired;
use OCA\Mcp\Access\Policy\ReceiptNotFound;

/** Public group preview and save contract inherited by the historical policy facade. */
interface GroupGrantCommandsContract {
	/**
	 * The preview of a group policy (contracts §2.3), taken at the expected revision: what the save would change,
	 * the group's member count and whether it can grant. It writes a preview event (no policy change, no revision
	 * step) that setGroupGrants() must present again within PREVIEW_TTL seconds. A group that cannot grant gets no
	 * preview for a non-empty set (422).
	 *
	 * @param string $expectedRevision the revision the administrator's page was built from
	 * @param string $actor uid of the administrator
	 * @param string $gid the group, exact bytes
	 * @param mixed $grants target set, module => list of operations (PolicyEventPayload::groupGrants())
	 * @param Closure(array{gid:string, grants:array, current:array, withdraws:bool, holders:list<string>}): array{trusted:bool, members:list<string>, cuts:list<string>, sources?:list<array{groupId:string, state:?string, gen:?string}>} $admit
	 *     the external phase, without any transaction: the fresh source predicate, the member list and, when the
	 *     set withdraws eligibility, which of the given holders lose their last source, with the lifecycle of every
	 *     group those decisions read (GroupGrantsService::admit())
	 * @return array{previewId:string, revision:string, groupId:string, grants:array, current:array, state:?string, members:int, trusted:bool, withdraws:bool, revokes:int}
	 * @throws \InvalidArgumentException for an invalid revision, group or set
	 * @throws GroupSourceUntrusted for a non-empty set on a group that cannot grant
	 * @throws PolicyConflict when the expected revision is stale
	 * @throws PolicyUnavailable when the storage or the directory cannot be read, or the outcome is uncertain
	 */
	public function previewGroupGrants(string $expectedRevision, string $actor, string $gid, mixed $grants, Closure $admit): array;

	/**
	 * Saves the whole policy of a group (contracts §2.3, §3.3.5) from one of the caller's previews: the grant rows are
	 * replaced by $grants, and the lifecycle becomes active with a new gen and policy_rev = the revision this commit
	 * writes; an empty set removes the rows and the lifecycle. Saving in pending_delete or needs_confirm substitutes
	 * the whole set, so "Confirm current policy" is this same call with the current set (coordinator clarification 5).
	 * Granting never touches connected or a user override.
	 *
	 * The save presents the preview's revision, group, set, member hash and lifecycle again. In the external phase the
	 * members are enumerated anew and the source predicate read fresh; a different member set (even of the same
	 * count) or lifecycle is a conflict with nothing written, and a non-empty set on a group that cannot grant is
	 * refused. Under the mutex only local state is compared: the lifecycle (whose pending_delete transition does not
	 * move the revision) and the rows, as read in the external phase.
	 *
	 * Withdrawing _system/eligible follows the holders rule: H0, every live credential holder with its generations,
	 * is read before the external phase, which decides who loses its last source; under the mutex H1 is read again,
	 * and any new holder or issue_seq (an issuance between the phases) replays the attempt, at most twice, then 409.
	 * Otherwise the rows, the lifecycle and the cuts (revoked_below = issue_seq) of the holders who lose commit
	 * together. Holders with another source keep their credentials, so that source must still be what the decision
	 * read: the lifecycle of every group the external phase relied on is compared under the mutex too (a deletion's
	 * pending_delete moves no revision), and each active one gets a new gen in the same commit. A deletion's pre-event
	 * that read such a group before this commit then fails its conditional transition, and its After suspends the
	 * group (needs_confirm) instead of purging a source this save relied on without a cause for its holders.
	 *
	 * Every lifecycle write is conditioned on the gen read under the mutex (the pre-event transition never takes the
	 * mutex, so it can land after that read): a lifecycle that moved in between replays the attempt.
	 *
	 * @param string $expectedRevision the preview's revision
	 * @param string $actor uid of the administrator; only the preview's own may save it
	 * @param string $gid the preview's group
	 * @param mixed $grants the preview's target set
	 * @param string $previewId id returned by previewGroupGrants()
	 * @param Closure $admit the external phase, as for previewGroupGrants()
	 * @return array{revision:string, changed:bool, state:?string, revoked:int} state: the lifecycle after the save
	 * @throws \InvalidArgumentException for an invalid revision, group or set, or one that differs from the preview
	 * @throws ReceiptNotFound for an unknown preview, or another administrator's
	 * @throws ReceiptExpired for the caller's preview past PREVIEW_TTL
	 * @throws GroupSourceUntrusted for a non-empty set on a group that cannot grant; nothing is written
	 * @throws PolicyConflict when the revision, the member set or the lifecycle changed since the preview, or the
	 *     holders kept changing
	 * @throws PolicyUnavailable when the storage or the directory cannot be read, or the outcome is uncertain
	 */
	public function setGroupGrants(string $expectedRevision, string $actor, string $gid, mixed $grants, string $previewId, Closure $admit): array;
}
