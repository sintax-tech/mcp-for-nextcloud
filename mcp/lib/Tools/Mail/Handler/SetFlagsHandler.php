<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail\Handler;

use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Mail\Flag\MailFlagEvent;
use OCA\Mcp\Tools\Mail\Flag\MailFlagMessages;
use OCA\Mcp\Tools\Mail\Flag\MailFlagPlan;
use OCA\Mcp\Tools\Mail\Flag\MailFlagRequest;
use OCA\Mcp\Tools\Mail\Flag\MailFlagStore;
use OCA\Mcp\Tools\Mail\MailMessageLocator;
use OCA\Mcp\Tools\Mail\MailServices;
use OCA\Mcp\Tools\Mail\MailUnavailableException;
use OCA\Mcp\Tools\PlanChanged;
use OCA\Mcp\Tools\PlanState;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolResult;

/**
 * Backs `mail_set_flags`: read/unread and flagged/not flagged on one message of the user, as asked, never toggled.
 *
 * A call without `confirm` gets the plan ({@see MailFlagPlan}), from the Mail app's cache, without contacting the mail
 * server. A confirmed call resolves the message again through the user's own accounts ({@see MailMessageLocator}),
 * requires the `plan_state` of the plan and answers with the new plan when the cached message or the arguments are not
 * the approved ones ({@see PlanChanged}); then it sets the flags on the server only while the server holds that message
 * ({@see MailFlagStore}) and tells the Mail app of each change as its own flag action does ({@see MailFlagEvent}), so
 * its cache follows.
 */
final class SetFlagsHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = MailFlagPlan::TOOL;

	/**
	 * Receives the locator, the plan, the server change, the cache notice and the Mail classes.
	 *
	 * @param MailMessageLocator $locator message id to the user's own message, folder and account
	 * @param MailFlagPlan $plans the plan and its state
	 * @param MailFlagStore $store sets the flags on the server
	 * @param MailFlagEvent $events tells the Mail app of a changed flag
	 * @param MailServices $services resolver of the Mail classes
	 * @return void
	 */
	public function __construct(
		private MailMessageLocator $locator,
		private MailFlagPlan $plans,
		private MailFlagStore $store,
		private MailFlagEvent $events,
		private MailServices $services,
	) {
	}

	/**
	 * The plan of a call without confirm.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments: message_id, seen, flagged
	 * @param string $userId authenticated user UID
	 * @return array<string, mixed> the plan
	 * @throws ArgumentValidationException when no flag is asked
	 * @throws ToolFailure when the message is not the user's
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function preview(array $arguments, string $userId): array {
		$request = MailFlagRequest::from($arguments);
		return $this->plans->plan($userId, $this->locator->locate($userId, (int)$arguments['message_id']), $request);
	}

	/**
	 * The confirmed change.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments: message_id, seen, flagged, plan_state
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>} MCP result
	 * @throws ArgumentValidationException when no flag is asked or plan_state is missing
	 * @throws PlanChanged when the message or the arguments are not the ones of the approved plan
	 * @throws ToolFailure when the message is not the user's, changed on the server, is gone or the server failed
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function handle(array $arguments, string $userId): array {
		$request = MailFlagRequest::from($arguments);
		PlanState::require($arguments);
		$found = $this->locator->locate($userId, (int)$arguments['message_id']);
		if (!PlanState::matches($this->plans->state($userId, $found, $request), $arguments)) {
			throw new PlanChanged($this->plans->plan($userId, $found, $request, true));
		}
		$eventClass = $this->services->flagEventClass($userId);
		$result = $this->store->apply($userId, $found['account'], $found['mailbox'], $found['message'], $request);
		$notes = [];
		foreach ($result['changed'] as $flag => $set) {
			try {
				$this->events->dispatch($eventClass, $found['account'], $found['mailbox'], $found['message'], $flag, $set);
			} catch (\Throwable) {
				// The server already holds the change; the Mail app catches its cache up at its next synchronization.
				$notes = [MailFlagMessages::cacheBehind()];
			}
		}
		if ($result['changed'] === []) {
			$notes[] = MailFlagMessages::alreadySet();
		}
		$after = array_replace($result['before'], $result['changed']);
		return ToolResult::json([
			'messageId' => (int)$found['message']->getId(),
			'changed' => array_map(static fn (string $flag, bool $set): array => ['flag' => $flag, 'to' => MailFlagMessages::state($flag, $set)],
				array_keys($result['changed']), $result['changed']),
			'flags' => ['seen' => $after['seen'], 'flagged' => $after['flagged']],
		] + ($notes === [] ? [] : ['notes' => array_values(array_unique($notes))]));
	}
}
