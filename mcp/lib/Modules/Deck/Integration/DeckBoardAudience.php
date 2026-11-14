<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Service\PermissionService;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\ISession;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;

/**
 * Who can read the board behind a list: its sharing entries, read with the actor's own read permission on the list.
 *
 * Users, groups and circles are named as Deck stores them and never expanded: a group or a circle stands for its
 * current and future members. Nothing is written and nothing is resolved beyond a display name.
 */
final class DeckBoardAudience {
	/** Deck's ACL types, the same numbers on every supported Deck. */
	private const TYPES = [0 => 'user', 1 => 'group', 7 => 'circle'];

	private DeckServiceContext $services;
	private DeckRecipientDirectory $directory;

	/**
	 * @param ContainerInterface $container lazy Deck services of this request
	 * @param ISession $session the native session, checked before the actor is bound
	 * @param IUserManager $users display names of the users the board is shared with
	 * @param IGroupManager $groups display names of the groups the board is shared with
	 * @param IAppManager $apps the Circles enablement the ACL reader of deck_link_file checks
	 */
	public function __construct(ContainerInterface $container, ISession $session, private IUserManager $users, private IGroupManager $groups, IAppManager $apps) {
		$this->services = new DeckServiceContext($container, $session);
		// The same raw ACL read deck_link_file uses, after the read check below authorized the board.
		$this->directory = new DeckRecipientDirectory($this->services, $users, $groups, $apps);
	}

	/**
	 * @param string $userId the actor
	 * @param int $stackId the list the card goes to
	 * @return list<array{type: string, id: string, displayName: string, edit: bool, share: bool, manage: bool}> every
	 *     sharing entry of its board; empty for a board shared with nobody
	 * @throws \Throwable any Deck refusal or failure, so the caller never takes an unreadable board for a private one
	 */
	public function ofStack(string $userId, int $stackId): array {
		$this->services->bindUser($userId);
		/** @var PermissionService $permissions */
		$permissions = $this->services->get(PermissionService::class);
		/** @var StackMapper $stacks */
		$stacks = $this->services->get(StackMapper::class);
		$permissions->checkPermission($stacks, $stackId, Acl::PERMISSION_READ);
		$boardId = $stacks->findBoardId($stackId) ?? throw new \RuntimeException('The list has no board');
		return array_values(array_map(fn (array $acl): array => $this->entry($acl), $this->directory->acls($boardId)));
	}

	/**
	 * @param array{type:int,participant:string,edit:bool,share:bool,manage:bool} $acl one sharing entry of the board
	 * @return array{type: string, id: string, displayName: string, edit: bool, share: bool, manage: bool} the entry, named
	 */
	private function entry(array $acl): array {
		$type = self::TYPES[$acl['type']] ?? 'other';
		$id = $acl['participant'];
		$name = match ($type) {
			'user' => $this->users->get($id)?->getDisplayName(),
			'group' => $this->groups->get($id)?->getDisplayName(),
			default => null,
		};
		return ['type' => $type, 'id' => $id, 'displayName' => $name === null || $name === '' ? $id : $name,
			'edit' => $acl['edit'], 'share' => $acl['share'], 'manage' => $acl['manage']];
	}
}
