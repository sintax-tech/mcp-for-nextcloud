<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

use OCA\Circles\Model\Member;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\AclMapper;
use OCA\Deck\Service\CirclesService;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCA\Mcp\Modules\Deck\Integration\DeckServiceContext;

/**
 * The same native directories and inherited membership used by Deck, with strict completeness checks.
 */
final class DeckRecipientDirectory implements \OCA\Mcp\Modules\Deck\Recipients\RecipientDirectory {
	/**
	 * Receive native directories and the existing lazy Deck service context, without eager optional-app DI.
	 *
	 * @param DeckServiceContext $services Injected optional Deck services.
	 * @param IUserManager $users Native account resolution, including local guest backends.
	 * @param IGroupManager $groups Native group membership.
	 * @param IAppManager $apps Current Circles enablement, checked before resolving its optional classes.
	 * @return void
	 */
	public function __construct(private DeckServiceContext $services, private IUserManager $users,
		private IGroupManager $groups, private IAppManager $apps) {
	}

	/**
	 * Read every ACL entry only after the resolver has authorized the source.
	 *
	 * @param int $boardId Authorized native board.
	 * @return list<array{type:int,participant:string,edit:bool,share:bool,manage:bool}> Complete ACL rows.
	 */
	public function acls(int $boardId): array {
		return array_map(static fn (Acl $acl): array => ['type' => $acl->getType(),
			'participant' => $acl->getParticipant(), 'edit' => $acl->getPermissionEdit(),
			'share' => $acl->getPermissionShare(), 'manage' => $acl->getPermissionManage()],
			$this->services->get(AclMapper::class)->findAll($boardId));
	}

	/**
	 * Resolve an actual account; missing users never disappear silently from the portrait.
	 *
	 * @param string $uid Stable native identity to resolve.
	 * @return IUser Existing account.
	 * @throws RecipientResolutionException When the identity cannot be resolved.
	 */
	public function user(string $uid): IUser {
		return $this->users->get($uid) ?? throw \OCA\Mcp\Modules\Deck\Recipients\RecipientResolutionException::incomplete();
	}

	/**
	 * Expand the native group after rejecting a known oversized native count.
	 *
	 * count() may be false, zero or an underestimate. It is only an early refusal optimization;
	 * the shared post-load work/time/person limits remain necessary and do not bound native allocation.
	 *
	 * @param string $groupId Existing group identity.
	 * @param RecipientExpansion $budget Shared operation-local work/time accounting.
	 * @return iterable<IUser> Complete native membership.
	 * @throws RecipientResolutionException On missing group or an exhausted budget.
	 */
	public function group(string $groupId, \OCA\Mcp\Modules\Deck\Recipients\RecipientExpansion $budget): iterable {
		$budget->work();
		$group = $this->groups->get($groupId) ?? throw \OCA\Mcp\Modules\Deck\Recipients\RecipientResolutionException::incomplete();
		if ($group->count() > \OCA\Mcp\Modules\Deck\Recipients\RecipientExpansion::MAX_RECIPIENTS) {
			throw \OCA\Mcp\Modules\Deck\Recipients\RecipientResolutionException::limit();
		}
		foreach ($group->getUsers() as $user) {
			$budget->work();
			yield $this->user($user->getUID());
		}
		$budget->checkpoint();
	}

	/**
	 * Expand inherited circle readers, distinguishing intermediate containers from people.
	 *
	 * Like Deck, levels below MEMBER are not readers. Unlike Deck's silent filtering, an effective
	 * non-local or unresolved person refuses the operation. Local group/circle entries are structural:
	 * validate their identities but use the native inherited user rows rather than counting them as people.
	 * Circle containers require the existence/consistency of getBasedOn()/getSingleId(), including hidden system
	 * group circles. getUserId() is their display name, not their circle identity; Deck getCircle filters them.
	 * The TYPE_GROUP branch is defensive: actual inherited groups in NC32–35 use TYPE_CIRCLE.
	 * Member::isLocal() supplies the native origin. The joined container has no owner loaded, so its
	 * Circle::isLocal() cannot classify origin; getBasedOn() verifies the matching structural identity only.
	 *
	 * @param string $circleId Existing local circle identity.
	 * @param RecipientExpansion $budget Shared work/time accounting, including ignored structural entries.
	 * @return iterable<IUser> Complete current local readers.
	 * @throws RecipientResolutionException On absent Circles, non-local circle, unsupported identity, missing container or budget failure.
	 */
	public function circle(string $circleId, \OCA\Mcp\Modules\Deck\Recipients\RecipientExpansion $budget): iterable {
		$budget->work();
		if (!$this->apps->isEnabledForUser('circles')) {
			throw \OCA\Mcp\Modules\Deck\Recipients\RecipientResolutionException::incomplete();
		}
		$circles = $this->services->get(CirclesService::class);
		if (!$circles->isCirclesEnabled()) {
			throw \OCA\Mcp\Modules\Deck\Recipients\RecipientResolutionException::incomplete();
		}
		$circle = $circles->getCircle($circleId) ?? throw \OCA\Mcp\Modules\Deck\Recipients\RecipientResolutionException::incomplete();
		if (!$circle->isLocal()) {
			throw \OCA\Mcp\Modules\Deck\Recipients\RecipientResolutionException::unsupported();
		}
		foreach ($circle->getInheritedMembers(true) as $member) {
			$budget->work();
			if ($member->getLevel() < Member::LEVEL_MEMBER) {
				continue;
			}
			if (!$member->isLocal()) {
				throw \OCA\Mcp\Modules\Deck\Recipients\RecipientResolutionException::unsupported();
			}
			$id = $member->getUserId();
			switch ($member->getUserType()) {
				case Member::TYPE_USER:
					yield $this->user($id);
					break;
				case Member::TYPE_GROUP:
					if ($this->groups->get($id) === null) {
						throw \OCA\Mcp\Modules\Deck\Recipients\RecipientResolutionException::incomplete();
					}
					break;
				case Member::TYPE_CIRCLE:
					$container = $member->getBasedOn();
					if ($container === null || $container->getSingleId() !== $member->getSingleId()) {
						throw \OCA\Mcp\Modules\Deck\Recipients\RecipientResolutionException::incomplete();
					}
					break;
				default:
					throw \OCA\Mcp\Modules\Deck\Recipients\RecipientResolutionException::unsupported();
			}
		}
		$budget->checkpoint();
	}
}
