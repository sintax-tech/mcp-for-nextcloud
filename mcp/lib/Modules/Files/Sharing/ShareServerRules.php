<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Sharing;

use OCA\Mcp\Modules\Files\Presentation\SharingMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;

/** Checks administrator restrictions before sharing. */
final class ShareServerRules {
	/**
	 * Constructs the sharing collaborator.
	 *
	 * @param IShareManager $shareManager Public sharing settings.
	 * @param IUserManager $userManager User lookup.
	 * @param IGroupManager $groupManager Group membership lookup.
	 */
	public function __construct(
		private IShareManager $shareManager,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
	) {}

	/**
	 * The administrator's rules on links the core would refuse with an untranslated exception (Manager::canShare and
	 * linkCreateChecks of stable33), read through the public getters.
	 *
	 * @param string $uid authenticated user, the sharer
	 * @throws ToolFailure naming the rule that refuses
	 */
	public function assertLinksAllowed(string $uid): void {
		if (!$this->shareManager->shareApiEnabled()) {
			throw new ToolFailure(SharingMessages::shareDisabled());
		}
		if ($this->shareManager->sharingDisabledForUser($uid)) {
			throw new ToolFailure(SharingMessages::shareDisabledForYou());
		}
		if (!$this->shareManager->shareApiAllowLinks($this->userManager->get($uid))) {
			throw new ToolFailure(SharingMessages::shareLinksDisabled());
		}
	}

	/**
	 * The administrator's sharing rules the core would refuse with an untranslated exception (Manager::canShare,
	 * userCreateChecks and groupCreateChecks of stable33), read through the public getters.
	 *
	 * @param string $uid authenticated user, the sharer
	 * @param ShareRecipient $recipient person or group
	 * @throws ToolFailure naming the rule that refuses
	 */
	public function assertServerAllows(string $uid, ShareRecipient $recipient): void {
		if (!$this->shareManager->shareApiEnabled()) {
			throw new ToolFailure(SharingMessages::shareDisabled());
		}
		if ($this->shareManager->sharingDisabledForUser($uid)) {
			throw new ToolFailure(SharingMessages::shareDisabledForYou());
		}
		if ($recipient->kind === ShareRecipient::GROUP && !$this->shareManager->allowGroupSharing()) {
			throw new ToolFailure(SharingMessages::shareGroupsDisabled());
		}
		if (!$this->shareManager->shareWithGroupMembersOnly()) {
			return;
		}
		$sharer = $this->userManager->get($uid);
		$excluded = $this->shareManager->shareWithGroupMembersOnlyExcludeGroupsList();
		if ($recipient->kind === ShareRecipient::GROUP) {
			$group = $this->groupManager->get($recipient->id);
			if ($sharer === null || $group === null || in_array($recipient->id, $excluded, true) || !$group->inGroup($sharer)) {
				throw new ToolFailure(SharingMessages::shareOnlyOwnGroups());
			}
			return;
		}
		$other = $this->userManager->get($recipient->id);
		$common = $sharer === null || $other === null ? [] : array_diff(
			array_intersect($this->groupManager->getUserGroupIds($sharer), $this->groupManager->getUserGroupIds($other)),
			$excluded,
		);
		if ($common === []) {
			throw new ToolFailure(SharingMessages::shareOnlyGroupMembers());
		}
	}
}
