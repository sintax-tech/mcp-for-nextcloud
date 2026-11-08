<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Collectives\Removal\RemovalReadback;

/**
 * Every string of the members of a collective. Descriptions read by the model are fixed English constants; what the
 * person sees is translated through {@see Translator}, like {@see CollectivesMessages}.
 */
final class MembershipMessages {
	public const TOOL_LIST_DESCRIPTION = 'Lists the members of one collective (accounts and groups of its team) with their level and status, '
		. 'and whether the user may manage them.';
	public const TOOL_ADD_DESCRIPTION = 'Adds an account or a group of this server to a collective as member; returns a plan first.';
	public const TOOL_LEVEL_DESCRIPTION = 'Changes the level of a member of a collective to member, moderator or admin; returns a plan first.';
	public const TOOL_REMOVE_DESCRIPTION = 'Removes a member from a collective, or cancels a pending invitation or request; returns a plan first. '
		. 'Teams applies the removal in the background and the receipt waits up to ' . RemovalReadback::SECONDS . ' seconds to see it. '
		. 'A receipt with status uncertain is not a reason to remove again: check collectives_list_members first.';
	public const PARAM_TYPE = 'user for an account, group for a group of this server.';
	public const PARAM_ID = 'Account id (as Search people returns it) or group id.';
	public const PARAM_MEMBER_ID = 'member_id of the member, as collectives_list_members returns it.';
	public const PARAM_LEVEL = 'New level: member, moderator or admin. The owner cannot be changed here.';

	/** Constant holder and static helpers only; never instantiated. */
	private function __construct() {
	}

	/** @return string only admins of the collective manage members */
	public static function adminsOnly(): string {
		return Translator::t('Only admins of this collective can manage its members, as in Collectives.');
	}

	/** @return string a personal or single team */
	public static function fixedTeam(): string {
		return Translator::t('The members of this team cannot be changed.');
	}

	/** @return string the target account or group does not exist */
	public static function unknownTarget(): string {
		return Translator::t('No account or group of this server has this id.');
	}

	/** @return string the actor targets themselves */
	public static function notYourself(): string {
		return Translator::t('You cannot change your own membership here; use Collectives to leave a collective.');
	}

	/** @return string already a member */
	public static function alreadyMember(): string {
		return Translator::t('This account or group is already a member of the collective.');
	}

	/** @return string already invited */
	public static function alreadyInvited(): string {
		return Translator::t('This account already has a pending invitation to the collective.');
	}

	/** @return string blocked in the team */
	public static function blocked(): string {
		return Translator::t('This account is blocked in the team of the collective; unblock it in Collectives or Teams first.');
	}

	/** @return string a group in a team that requires invitations */
	public static function groupInvite(): string {
		return Translator::t('This team turns new members into invitations, and nobody can accept an invitation for a group; add its accounts one by one instead.');
	}

	/** @return string the owner */
	public static function ownerUnchanged(): string {
		return Translator::t('The owner of the collective cannot be removed or have another level; transferring ownership is not offered here.');
	}

	/** @return string a pending member has no level to change */
	public static function pendingLevel(): string {
		return Translator::t('This invitation or request is still pending: its level can only change after it is accepted.');
	}

	/** @return string the member is unknown */
	public static function unknownMember(): string {
		return Translator::t('This collective has no member with this member_id.');
	}

	/** @return string the new member joins directly */
	public static function joinsDirectly(): string {
		return Translator::t('They become a member right away and can read the collective, and edit it as its permissions allow.');
	}

	/** @return string the account gets an invitation */
	public static function becomesInvitation(): string {
		return Translator::t('This team turns new members into invitations: the account gets an invitation (level none) to accept, and a Nextcloud notification about it.');
	}

	/** @return string a pending request is accepted */
	public static function acceptsRequest(): string {
		return Translator::t('This account asked to join: adding it accepts the request, and it becomes a member right away.');
	}

	/**
	 * @param int $count accounts in the group
	 * @return string every account of the group gets access
	 */
	public static function groupAccess(int $count): string {
		return Translator::t('Every account of this group gets access, %d now and whoever joins the group later; groups get no notification.', [$count]);
	}

	/** @return string no direct email */
	public static function noDirectEmail(): string {
		return Translator::t('Teams sends no email of its own for accounts or groups; a person’s own notification settings may still forward the notification by email.');
	}

	/** @return string Teams may record the identity of the account */
	public static function identityMayBeRecorded(): string {
		return Translator::t('Teams has not used this account yet: it records its internal identity, as when it is added in the browser, even if the addition is refused.');
	}

	/** @return string Teams may record the group and the app as inviter */
	public static function groupMayBeRecorded(): string {
		return Translator::t('Teams may record the group as a hidden internal team, as when it is added in the browser, and records the addition as made by the Teams app.');
	}

	/**
	 * @param string $from current level
	 * @param string $to new level
	 * @return string the level change
	 */
	public static function levelChange(string $from, string $to): string {
		return Translator::t('Level %s becomes %s.', [$from, $to]);
	}

	/** @return string what an admin may do */
	public static function adminPowers(): string {
		return Translator::t('Admins manage the members and the settings of the collective.');
	}

	/** @return string the level stays */
	public static function levelUnchanged(): string {
		return Translator::t('Nothing would change: the member already has this level.');
	}

	/** @return string a removed member loses access */
	public static function losesAccess(): string {
		return Translator::t('They lose access to the collective and its pages; the pages they wrote stay.');
	}

	/** @return string a group removal */
	public static function groupLosesAccess(): string {
		return Translator::t('Accounts that reach the collective only through this group lose access.');
	}

	/** @return string a pending removal */
	public static function cancelsPending(): string {
		return Translator::t('The pending invitation or request is cancelled.');
	}

	/** @return string receipt: the change was observed */
	public static function applied(): string {
		return Translator::t('Change observed by reading the team of the collective again.');
	}

	/** @return string receipt: Teams refused and nothing changed in the membership */
	public static function notApplied(): string {
		return Translator::t('Teams refused the change and the membership is as before; other internal records of Teams may still have been written, as listed.');
	}

	/** @return string receipt: something else was observed */
	public static function partial(): string {
		return Translator::t('Teams answered, but reading the team again shows another result than the one requested; nothing is retried.');
	}

	/** @return string receipt: Teams stored the membership, then failed */
	public static function storedThenFailed(): string {
		return Translator::t('Teams stored the requested membership but then reported an error; what it does afterwards, such as notifying the person, is not proven. Nothing is retried.');
	}

	/** @return string receipt: Teams accepted the change, which was not visible yet when the team was read again */
	public static function notVisibleYet(): string {
		return Translator::t('Teams accepted the change but applies it in the background, and it was not visible yet when the team was read again; check the members in Collectives before trying again. Nothing is retried.');
	}

	/** @return string receipt: the team could not be read again */
	public static function uncertain(): string {
		return Translator::t('The change was sent to Teams but the team could not be read again; check the members in Collectives before trying again.');
	}
}
