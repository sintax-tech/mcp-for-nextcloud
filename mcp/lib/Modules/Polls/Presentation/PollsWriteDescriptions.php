<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Presentation;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\ToolDefinition;

/** Describes the distinct native effects before the client chooses a tool. */
final class PollsWriteDescriptions {
	/**
	 * @param string $tool Exact published mutation name.
	 * @return string English discovery text, including access and delivery caveats.
	 * @throws \LogicException When a mutation has no reviewed description.
	 */
	public static function of(string $tool): string {
		return match ($tool) {
			'polls_update_poll' => 'Update approved configuration fields of an existing poll. Type, owner, access and anonymity remain unchanged.',
			'polls_set_poll_state' => 'Close, reopen, archive or unarchive an existing poll to the requested state. Reopening clears scheduled expiration; an already satisfied state is a no-op.',
			'polls_add_option' => 'Add a text or date option to an existing poll without casting a vote. A matching deleted option can be restored with its preserved votes.',
			'polls_update_option' => 'Change the text or date value of an existing option. Existing votes stay attached; conflicting options are refused.',
			'polls_reorder_options' => 'Reorder every active option of a nonexpired text poll using a complete ordered ID list. Date options cannot be reordered.',
			'polls_remove_option' => 'Soft-delete an existing option. Votes are preserved and may reappear when the option is restored.',
			'polls_restore_option' => 'Restore a soft-deleted option with its existing votes. The plan identifies the option and preserved-vote risk.',
			'polls_add_share' => 'Add a typed recipient or public share to a poll. Type admin explicitly grants editing rights to the named recipient. Public sharing requires effective native policy; credential links are never returned.',
			'polls_update_share' => 'Lock, unlock, label, remove, restore or change the role of an existing share. set_admin grants editing rights to the named recipient; restoring or unlocking can reactivate delegated access. Credential links are never returned.',
			'polls_invite_share' => 'Send one native invitation to an unlocked individual share. Groups and public shares cannot be invited; an invitation marker does not prove email delivery.',
			default => throw new \LogicException('Unknown Polls mutation description'),
		} . ' Requires native edit permission, a complete plan and explicit confirmation; readback reports observed effects.';
	}
}
