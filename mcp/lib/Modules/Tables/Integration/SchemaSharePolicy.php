<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Integration;

use OCP\Share\IManager;
use OCP\IUserManager;
use OCP\IGroupManager;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\FileAccess\CommonMessages;

/** Our explicit sharing guards, including pins whose service omits them. */
class SchemaSharePolicy {
	/**
	 * @param IManager $shares Nextcloud share policy.
	 * @param IUserManager $users Users.
	 * @param IGroupManager $groups Groups.
	 */
	public function __construct(private IManager $shares, private IUserManager $users, private IGroupManager $groups) {}
	/**
	 * @param array{type:string,id:string} $recipient Exact recipient.
	 * @param string $uid Caller whose native manage has already been checked.
	 * @return void
	 */
	public function check(array $recipient, string $uid): void {
		if ($this->shares->sharingDisabledForUser($uid)) throw new ToolFailure(CommonMessages::forbidden());
		$id = $recipient['id'];
		if ($recipient['type'] === 'user' ? !$this->users->userExists($id) : !$this->groups->groupExists($id)) throw new ToolFailure(CommonMessages::notFound());
		if ($recipient['type'] === 'group' && !$this->shares->allowGroupSharing()) throw new ToolFailure(CommonMessages::forbidden());
		if ($this->shares->shareWithGroupMembersOnly()) {
			$caller = $this->users->get($uid);
			if ($caller === null) throw new ToolFailure(CommonMessages::forbidden());
			$groups = $this->groups->getUserGroupIds($caller);
			if (array_intersect($groups, $this->shares->shareWithGroupMembersOnlyExcludeGroupsList()) !== []) return;
			if ($recipient['type'] === 'group') $allowed = in_array($id, $groups, true);
			else {
				$user = $this->users->get($id);
				$allowed = $user !== null && array_intersect($groups, $this->groups->getUserGroupIds($user)) !== [];
			}
			if (!$allowed) throw new ToolFailure(CommonMessages::forbidden());
		}
	}
}
