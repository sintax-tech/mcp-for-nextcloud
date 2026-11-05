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

/** Plans an Outbox send without saving or submitting any message. */
final class SendMessagePreview {
	/**
	 * @param MailSendAccount $accounts actor account and Sent folder
	 * @param MailSendPlan $plans send snapshot and PlanState
	 */
	public function __construct(private MailSendAccount $accounts, private MailSendPlan $plans) {}

	/**
	 * The plan of a call without confirm.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments: account_id, to, cc, bcc, subject, body
	 * @param string $userId authenticated user UID
	 * @return array<string, mixed> the plan
	 * @throws ArgumentValidationException for a recipient, subject or text the message cannot carry, or no recipient
	 * @throws ToolFailure when the account is not the user's or has no Sent folder
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function plan(array $arguments, string $userId): array {
		$message = MailSendRequest::from($arguments);
		return $this->plans->plan($userId, $this->accounts->resolve($userId, (int)$arguments['account_id']), $message);
	}
}
