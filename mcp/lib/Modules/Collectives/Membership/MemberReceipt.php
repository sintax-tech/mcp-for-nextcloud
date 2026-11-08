<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Membership;

use OCA\Mcp\Modules\Collectives\Integration\CollectivesGateway;
use OCA\Mcp\Modules\Collectives\Presentation\MembershipMessages;
use Throwable;

/**
 * The receipt of a member write, from a fresh read of the team only. The membership is compared before, requested
 * and observed; whether it changed is stated apart from the other internal records Teams may write on the way
 * (`auxiliary_effects`), so a refusal never claims that nothing was written. Nothing is retried or undone.
 *
 * - `applied`: Teams answered and the read shows the requested membership;
 * - `not_applied`: Teams threw and the membership is as before;
 * - `partial`: the membership changed into something else than requested, or Teams threw after storing it: what
 *   follows the store in Teams (its events, the invitation notification) is then not proven (§14 R2.5);
 * - `uncertain`: the team could not be read again, or Teams answered and the read shows the membership still as
 *   before: Teams applies some changes in the background (a removal, see {@see \OCA\Mcp\Modules\Collectives\Removal\RemovalReadback}),
 *   so an unchanged membership is not a different result.
 */
final class MemberReceipt {
	/** Static helper only; never instantiated. */
	private function __construct() {
	}

	/**
	 * @param array{status:string, level:string}|null $before the membership before, null when there was none
	 * @param array{status:string, level:string}|null $requested the membership asked for, null for none
	 * @param array{status:string, level:string}|null|false $observed the membership read now, null for none, false when unread
	 * @param Throwable|null $error what Teams threw, null when it answered
	 * @param array<string, mixed> $extra what else the receipt carries
	 * @return array<string, mixed> the receipt
	 */
	public static function of(?array $before, ?array $requested, array|null|false $observed, ?Throwable $error, array $extra = []): array {
		$receipt = ['nativeCallAttempted' => true, 'before' => $before, 'requested' => $requested]
			+ ($error === null ? [] : ['native_error' => CollectivesGateway::failure($error)->getMessage()]);
		if ($observed === false) {
			return $receipt + ['status' => 'uncertain', 'membership_changed' => 'unknown', 'message' => MembershipMessages::uncertain()] + $extra;
		}
		$changed = $observed !== $before;
		$status = match (true) {
			$error === null && $observed === $requested => 'applied',
			$error !== null && !$changed => 'not_applied',
			$error === null && !$changed => 'uncertain',
			default => 'partial',
		};
		return $receipt + ['observed' => $observed, 'status' => $status, 'membership_changed' => $changed, 'message' => match (true) {
			$status === 'applied' => MembershipMessages::applied(),
			$status === 'not_applied' => MembershipMessages::notApplied(),
			$status === 'uncertain' => MembershipMessages::notVisibleYet(),
			$error !== null && $observed === $requested => MembershipMessages::storedThenFailed(),
			default => MembershipMessages::partial(),
		}] + $extra;
	}

	/**
	 * @param array<string, mixed>|null $member a member as projected, null for none
	 * @return array{status:string, level:string}|null the part of it a receipt compares
	 */
	public static function state(?array $member): ?array {
		return $member === null ? null : ['status' => (string)$member['status'], 'level' => (string)$member['level']];
	}
}
