<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Sending;

use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Modules\Mail\Integration\MailServices;
use OCA\Mcp\Modules\Mail\MailUnavailableException;
use OCA\Mcp\Modules\Mail\Sending\MailSendAccount;
use OCA\Mcp\Modules\Mail\Integration\MailSendOutbox;
use OCA\Mcp\Modules\Mail\Sending\MailSendOutcome;
use OCA\Mcp\Modules\Mail\Sending\MailSendPlan;
use OCA\Mcp\Modules\Mail\Sending\MailSendRequest;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;

/**
 * Backs `mail_send_message`: one new message from one of the user's own accounts, through the Mail app's Outbox and send
 * chain.
 *
 * A call without `confirm` gets the plan ({@see MailSendPlan}) without contacting any server or writing to the Outbox. A
 * confirmed call resolves the account, its Sent folder and the sender again ({@see MailSendAccount}), requires the
 * `plan_state` of the plan and answers with the new plan when any of them or the arguments are not the approved ones
 * ({@see PlanChanged}). Then the Mail app saves and sends the message ({@see MailSendOutbox}) and the answer says what its
 * recorded status means ({@see MailSendOutcome}): sent (accepted by the SMTP server, not delivered), sent but not copied
 * to Sent, refused, not sent or uncertain. Each confirmed call sends again; the tool adds no retry and no deduplication
 * of its own beyond the Mail app's (which never sends again a message whose earlier send was unclear).
 */
final class SendMessageHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = MailSendPlan::TOOL;

	/**
	 * Receives the account, the plan, the send and the Mail classes.
	 *
	 * @param MailSendAccount $accounts the user's own account, its Sent folder and the sender
	 * @param MailSendPlan $plans the plan and its state
	 * @param MailSendOutbox $outbox saves and sends through the Mail app
	 * @param MailServices $services resolver of the Mail classes, for the status values
	 * @return void
	 */
	public function __construct(
		private MailSendAccount $accounts,
		private MailSendPlan $plans,
		private MailSendOutbox $outbox,
		private MailServices $services,
	) {
	}



	/**
	 * The confirmed send.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments with plan_state
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>} MCP result
	 * @throws ArgumentValidationException when plan_state is missing, or for a value the message cannot carry
	 * @throws PlanChanged when the account, the sender, the Sent folder or the arguments are not the approved ones
	 * @throws ToolFailure when the account is not the user's or has no Sent folder, the save failed (not sent) or the send
	 *     failed (outcome unknown)
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function handle(array $arguments, string $userId): array {
		PlanState::require($arguments);
		$message = MailSendRequest::from($arguments);
		$from = $this->accounts->resolve($userId, (int)$arguments['account_id']);
		if (!PlanState::matches($this->plans->state($userId, $from, $message), $arguments)) {
			throw new PlanChanged($this->plans->plan($userId, $from, $message, true));
		}
		$statuses = $this->services->localMessageStatuses($userId, MailSendOutcome::STATUSES);
		$result = $this->outbox->send($userId, $from['account'], $message);
		return ToolResult::json(['accountId' => (int)$from['account']->getId()] + MailSendOutcome::of($result, $statuses));
	}
}
