<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

use OCA\Deck\Db\Acl;
use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\StackMapper;
use OCA\Deck\Notification\NotificationHelper;
use OCA\Deck\Service\AssignmentService;
use OCA\Deck\Service\PermissionService;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Modules\Deck\Failures\DeckRetryWithheldException;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCP\IUserManager;
use OCP\Notification\IManager;

/** Validates accounts against a board and assigns or unassigns them on a card. */
final class DeckAssignments {
	/** Deferral of the request's assignment retries, kept so a deferral left open by a failed delivery stays ours. */
	private ?DeferredAssignmentNotice $deferred = null;

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
	 *
	 * The direct call is the one of 1.2.5: Deck notifies the assignee and the Notifications app sends the push inline.
	 * A retry with `$deferDelivery` first withdraws the notification the failed attempt left, through Deck's own
	 * `NotificationHelper::markCardAssignedAsRead()` as an unassignment does, and assigns again only when the
	 * Notifications app confirms that none is left: Deck's assignment always stores a new notification, so retrying
	 * over one that stayed would give the assignee two. Unconfirmed, the assignment is not retried and fails as it did
	 * in 1.2.5. Both steps run with the push delivered after them ({@see DeferredAssignmentNotice}).
	 * @param string $userId Authenticated caller.
	 * @param int $cardId Created card.
	 * @param string $assignee Account ID to assign.
	 * @param bool $deferDelivery Retry of an assignment that failed before Deck stored it.
	 * @return \OCA\Deck\Db\Assignment Persisted assignment.
	 * @throws \Throwable When a concurrent change prevents assignment, or a deferred delivery fails after the insert.
	 * @throws DeckRetryWithheldException When the notification of the failed attempt could not be withdrawn.
	 * @see DeckGatewayInterface::assignCardUser()
	 */
	public function assignCardUser(string $userId, int $cardId, string $assignee, bool $deferDelivery = false): Assignment {
		$this->services->bindUser($userId);
		/** @var AssignmentService $assignments */
		$assignments = $this->services->get(AssignmentService::class);
		if (!$deferDelivery) {
			return $assignments->assignUser($cardId, $assignee);
		}
		/** @var IManager $notifications */
		$notifications = $this->services->get(IManager::class);
		$this->deferred ??= new DeferredAssignmentNotice($notifications);
		if (!$this->withdrawFailedNotice($notifications, $cardId, $assignee)) {
			throw new DeckRetryWithheldException('The notification of the failed attempt is still there');
		}
		return $this->deferred->assign(static fn (): Assignment => $assignments->assignUser($cardId, $assignee));
	}

	/**
	 * Withdraws the "card assigned" notification a failed attempt stored before its push failed, and confirms it.
	 *
	 * @param IManager $notifications Manager whose apps hold the notification.
	 * @param int $cardId Card of the failed attempt.
	 * @param string $assignee Account the attempt notified.
	 * @return bool True only when the Notifications app reports no such notification left.
	 */
	private function withdrawFailedNotice(IManager $notifications, int $cardId, string $assignee): bool {
		try {
			/** @var CardMapper $cards */
			$cards = $this->services->get(CardMapper::class);
			/** @var NotificationHelper $helper */
			$helper = $this->services->get(NotificationHelper::class);
			$card = $cards->find($cardId);
			$this->deferred?->withdraw(static fn () => $helper->markCardAssignedAsRead($card, $assignee));
		} catch (\Throwable) {
			// Whether anything was withdrawn is read back below; a failure here alone proves nothing either way.
		}
		try {
			$left = $notifications->createNotification()->setApp('deck')->setUser($assignee)
				->setObject('card', (string)$cardId)->setSubject('card-assigned');
			return $notifications->getCount($left) === 0;
		} catch (\Throwable) {
			return false;
		}
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
