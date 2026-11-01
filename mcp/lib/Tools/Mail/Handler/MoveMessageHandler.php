<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail\Handler;

use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Tools\Mail\Link\MailLinks;
use OCA\Mcp\Tools\Mail\MailMessageLocator;
use OCA\Mcp\Tools\Mail\MailServices;
use OCA\Mcp\Tools\Mail\MailUnavailableException;
use OCA\Mcp\Tools\Mail\Move\MailMoveDestination;
use OCA\Mcp\Tools\Mail\Move\MailMoveMessages;
use OCA\Mcp\Tools\Mail\Move\MailMovePlan;
use OCA\Mcp\Tools\Mail\Move\MailMoveStore;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Backs `mail_move_message`: one message of the user to another folder of the same account.
 *
 * A call without `confirm` gets the plan ({@see MailMovePlan}), from the Mail app's cache, without contacting the mail
 * server. A confirmed call resolves the message and the destination again through the user's own accounts
 * ({@see MailMessageLocator}, {@see MailMoveDestination}), requires the `plan_state` of the plan and answers with the new
 * plan when the cached message, the destination or the arguments are not the approved ones ({@see PlanChanged}). A
 * destination that is the message's own folder moves nothing and contacts no server. Otherwise the message is moved only
 * while the server holds it ({@see MailMoveStore}) and the Mail app is told the source message is gone, as its own move
 * does (OCA\Mail\Events\MessageDeletedEvent, which takes the account, the folder and the UID in every supported release),
 * so its cache drops it; the destination joins the cache at the Mail app's next synchronization. After a move that
 * succeeded, a repeated confirmed call is refused: the cache no longer has the source message, or, when the cache could
 * not follow, the server no longer has it at that UID. After an uncertain move there is no such protection (a copy may
 * have been made while the original stayed, and the plan still holds): the answer tells the user to look in both
 * folders and not to repeat, and nothing is retried automatically.
 */
final class MoveMessageHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = MailMovePlan::TOOL;

	/**
	 * Receives the locator, the destination, the plan, the server move, the links and the Mail classes.
	 *
	 * @param MailMessageLocator $locator message id to the user's own message, folder and account
	 * @param MailMoveDestination $destinations destination folder and its kind
	 * @param MailMovePlan $plans the plan and its state
	 * @param MailMoveStore $store moves the message on the server
	 * @param MailLinks $links direct links into the Mail app, for the destination folder
	 * @param IEventDispatcher $events event dispatcher, for the Mail app's event
	 * @param MailServices $services resolver of the Mail classes
	 * @return void
	 */
	public function __construct(
		private MailMessageLocator $locator,
		private MailMoveDestination $destinations,
		private MailMovePlan $plans,
		private MailMoveStore $store,
		private MailLinks $links,
		private IEventDispatcher $events,
		private MailServices $services,
	) {
	}

	/**
	 * The plan of a call without confirm.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments: message_id, mailbox_id
	 * @param string $userId authenticated user UID
	 * @return array<string, mixed> the plan
	 * @throws ToolFailure when the message or the folder is not the user's, or the folder cannot be used
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function preview(array $arguments, string $userId): array {
		$found = $this->locator->locate($userId, (int)$arguments['message_id']);
		return $this->plans->plan($userId, $found, $this->destinations->resolve($userId, $found, (int)$arguments['mailbox_id']));
	}

	/**
	 * The confirmed move.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments: message_id, mailbox_id, plan_state
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>} MCP result
	 * @throws ArgumentValidationException when plan_state is missing
	 * @throws PlanChanged when the message, the destination or the arguments are not the ones of the approved plan
	 * @throws ToolFailure when the message or the folder is not the user's, cannot be used, changed on the server, the
	 *     server could not be read, or the move has no known outcome
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function handle(array $arguments, string $userId): array {
		PlanState::require($arguments);
		$found = $this->locator->locate($userId, (int)$arguments['message_id']);
		$destination = $this->destinations->resolve($userId, $found, (int)$arguments['mailbox_id']);
		if (!PlanState::matches($this->plans->state($userId, $found, $destination), $arguments)) {
			throw new PlanChanged($this->plans->plan($userId, $found, $destination, true));
		}
		$answer = [
			'messageId' => (int)$found['message']->getId(),
			'fromMailboxId' => (int)$found['mailbox']->getId(),
			'toMailboxId' => (int)$destination['mailbox']->getId(),
			'toUrl' => $this->links->mailbox((int)$destination['mailbox']->getId()),
		];
		if ($destination['kind'] === MailMoveDestination::SAME) {
			return ToolResult::json($answer + ['moved' => false, 'notes' => [MailMoveMessages::alreadyThere()]]);
		}
		$eventClass = $this->services->deleteEventClass($userId);
		$newUid = $this->store->move($userId, $found['account'], $found['mailbox'], $found['message'], $destination['mailbox']);
		$notes = [];
		try {
			$this->events->dispatchTyped(new $eventClass($found['account'], $found['mailbox'], (int)$found['message']->getUid()));
		} catch (\Throwable) {
			// The server already moved the message; the Mail app catches its cache up at its next synchronization.
			$notes[] = MailMoveMessages::cacheBehind();
		}
		return ToolResult::json($answer + ['moved' => true, 'newUid' => $newUid] + ($notes === [] ? [] : ['notes' => $notes]));
	}
}
