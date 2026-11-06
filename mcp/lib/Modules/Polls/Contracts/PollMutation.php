<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Contracts;

use OCA\Mcp\Modules\Polls\Presentation\PollsWriteSchema;

/** Reviewed mutation identities; an unlisted name cannot select a native operation. */
enum PollMutation: string {
	case UpdatePoll = 'polls_update_poll';
	case SetPollState = 'polls_set_poll_state';
	case AddOption = 'polls_add_option';
	case UpdateOption = 'polls_update_option';
	case ReorderOptions = 'polls_reorder_options';
	case RemoveOption = 'polls_remove_option';
	case RestoreOption = 'polls_restore_option';
	case AddShare = 'polls_add_share';
	case UpdateShare = 'polls_update_share';
	case InviteShare = 'polls_invite_share';

	/**
	 * @return PollMutationKind The verb family owning planning and comparison rules.
	 */
	public function kind(): PollMutationKind {
		return match ($this) {
			self::UpdatePoll, self::SetPollState => PollMutationKind::Configuration,
			self::AddOption, self::UpdateOption, self::ReorderOptions, self::RemoveOption, self::RestoreOption => PollMutationKind::Options,
			self::AddShare, self::UpdateShare, self::InviteShare => PollMutationKind::Shares,
		};
	}
	/**
	 * @return string Exact independent grant, including the literal dotted operation.
	 */
	public function operation(): string {
		return PollsWriteSchema::OPERATIONS[$this->value];
	}
}
