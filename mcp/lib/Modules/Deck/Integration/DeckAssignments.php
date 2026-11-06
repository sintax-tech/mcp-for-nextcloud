<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Service\AssignmentService;
use OCA\Deck\Service\PermissionService;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCP\IUserManager;

/** Validates accounts against a board and assigns or unassigns them on a card. */
final class DeckAssignments {
	/**
	 * Store the collaborators this area calls. A method binds the caller only when that method did before.
	 *
	 * @param DeckServiceContext $services Lazily resolved Deck services shared with the facade.
	 */
	public function __construct(
		private DeckServiceContext $services
	) {
	}

	/**
	 * Validates accounts and their board access before a preview or creation.
	 * @param string $userId Authenticated caller.
	 * @param int $stackId Destination stack.
	 * @param list<string> $assignees Account IDs to assign.
	 * @return list<array{uid: string, displayName: string}> Unique validated accounts.
	 * @throws \InvalidArgumentException When any account is missing or lacks access.
	 * @see DeckGatewayInterface::validateAssignees()
	 */
	public function validateAssignees(string $userId, int $stackId, array $assignees): array {
		$this->services->bindUser($userId);
		/** @var PermissionService $permissions */
		$permissions = $this->services->get(PermissionService::class);
		/** @var StackMapper $stacks */
		$stacks = $this->services->get(StackMapper::class);
		$permissions->checkPermission($stacks, $stackId, Acl::PERMISSION_EDIT);
		$boardId = $stacks->findBoardId($stackId);
		/** @var IUserManager $users */
		$users = $this->services->get(IUserManager::class);
		$out = [];
		foreach (\OCA\Mcp\Modules\Deck\Editing\CardInput::assignees($assignees) as $uid) {
			$user = $users->get($uid);
			// Deck evaluates owner, direct ACL, group and circle membership for this account.
			if ($user === null || !($permissions->getPermissions($boardId, $uid)[Acl::PERMISSION_READ] ?? false)) {
				throw new ArgumentValidationException('Invalid argument: assignees', 'assignees',
					Translator::t('each account must exist and have access to the board'));
			}
			$out[] = ['uid' => $uid, 'displayName' => $user->getDisplayName()];
		}
		return $out;
	}

	/**
	 * Assigns one validated account through Deck's assignment service.
	 * @param string $userId Authenticated caller.
	 * @param int $cardId Created card.
	 * @param string $assignee Account ID to assign.
	 * @return \OCA\Deck\Db\Assignment Persisted assignment.
	 * @throws \Throwable When a concurrent change prevents assignment.
	 * @see DeckGatewayInterface::assignCardUser()
	 */
	public function assignCardUser(string $userId, int $cardId, string $assignee): Assignment {
		$this->services->bindUser($userId);
		/** @var AssignmentService $assignments */
		$assignments = $this->services->get(AssignmentService::class);
		return $assignments->assignUser($cardId, $assignee);
	}

	/**
	 * Removes one account from the assignees of a card through Deck's assignment service.
	 * @param string $userId Authenticated caller.
	 * @param int $cardId Card the account leaves.
	 * @param string $assignee Account ID to unassign.
	 * @return \OCA\Deck\Db\Assignment Removed assignment.
	 * @throws \Throwable When Deck refuses or a concurrent change prevents it.
	 * @see DeckGatewayInterface::unassignCardUser()
	 */
	public function unassignCardUser(string $userId, int $cardId, string $assignee): Assignment {
		$this->services->bindUser($userId);
		/** @var AssignmentService $assignments */
		$assignments = $this->services->get(AssignmentService::class);
		// Deck v1.17.5: `unassignUser(int $cardId, string $userId, int $type = 0)`; the user type is the default.
		return $assignments->unassignUser($cardId, $assignee);
	}
}
