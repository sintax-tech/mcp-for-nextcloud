<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail\Send;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\Mail\MailModule;
use OCA\Mcp\Tools\Mail\MailRows;
use OCA\Mcp\Tools\PlanState;
use OCP\App\IAppManager;

/**
 * The plan of a send, built without contacting any server or writing to the Outbox, and its {@see PlanState}.
 *
 * The plan shows the message as it will be sent: the recipients by field, the subject and the text, which the caller
 * wrote; the account and its Sent folder by id, and the sender address and the folder path only for a user with
 * `mail.read` (without it, a note says the message leaves from the account's own address), so the send grant alone reads
 * nothing of the mailbox through a plan. It always says the message may leave the organization and cannot be undone,
 * that "sent" is the SMTP server's acceptance, not delivery, and that each confirmation sends again.
 *
 * With Bcc, a note says when the copy in Sent will not, or may not, show the Bcc recipients ({@see MailSendBccCopy}); they
 * receive the message either way.
 *
 * The plan state covers the account, the sender (name and address, so a changed account address needs a new plan), the
 * Sent folder (id and path), the whole message and that Bcc note. It is no receipt: confirming the same plan again sends
 * again.
 */
class MailSendPlan {
	/** MCP tool name of the plan. */
	public const TOOL = 'mail_send_message';

	/**
	 * Receives the plan states and the grants.
	 *
	 * @param PlanState $states the instance's plan states
	 * @param GrantPolicy $grants who may read what, for the sender address and the folder path
	 * @param IAppManager $apps app manager, for the installed Mail version of the Bcc note
	 * @return void
	 */
	public function __construct(
		private PlanState $states,
		private GrantPolicy $grants,
		private IAppManager $apps,
	) {
	}

	/**
	 * The plan.
	 *
	 * @param string $userId authenticated user UID
	 * @param array{account:object, sent:object, senderName:string, senderAddress:string} $from the account, its Sent
	 *     folder and the sender
	 * @param MailSendRequest $message the message asked for
	 * @param bool $changed whether a confirmed call found this instead of the approved plan: a warning says so first
	 * @return array<string, mixed> the plan
	 */
	public function plan(string $userId, array $from, MailSendRequest $message, bool $changed = false): array {
		$target = ['accountId' => (int)$from['account']->getId(), 'sentMailboxId' => (int)$from['sent']->getId()];
		$notes = [MailSendMessages::mayLeave(), MailSendMessages::acceptedNotDelivered(), MailSendMessages::eachConfirmSends()];
		$bccNote = MailSendBccCopy::note(MailSendBccCopy::of($message, $this->apps));
		if ($bccNote !== null) {
			$notes[] = $bccNote;
		}
		if ($this->grants->granted($userId, MailModule::MODULE, 'read')) {
			$target['from'] = MailRows::text($from['senderAddress']);
			$target['fromName'] = MailRows::text($from['senderName']);
			$target['sentPath'] = MailRows::text((string)$from['sent']->getName());
		} else {
			$notes[] = MailSendMessages::fromOwnAddress();
		}
		return [
			'action' => self::TOOL,
			'target' => $target,
			'mail' => $message->recipients + ['subject' => $message->subject, 'body' => $message->body],
			'message' => CommonMessages::planNothingChanged(),
			'notes' => $notes,
			PlanState::ARGUMENT => $this->state($userId, $from, $message),
		] + ($changed ? ['warnings' => [['message' => MailSendMessages::planChanged()]]] : []);
	}

	/**
	 * The plan state of a send.
	 *
	 * @param string $userId authenticated user UID
	 * @param array{account:object, sent:object, senderName:string, senderAddress:string} $from the account, its Sent
	 *     folder and the sender
	 * @param MailSendRequest $message the message asked for
	 * @return string the state
	 */
	public function state(string $userId, array $from, MailSendRequest $message): string {
		return $this->states->of(self::TOOL, $userId, [
			'account' => (int)$from['account']->getId(),
			'senderName' => $from['senderName'],
			'senderAddress' => $from['senderAddress'],
			'sent' => (int)$from['sent']->getId(),
			'sentPath' => (string)$from['sent']->getName(),
			'recipients' => $message->recipients,
			'subject' => $message->subject,
			'body' => hash('sha256', $message->body),
			'bccCopy' => MailSendBccCopy::of($message, $this->apps),
		]);
	}
}
