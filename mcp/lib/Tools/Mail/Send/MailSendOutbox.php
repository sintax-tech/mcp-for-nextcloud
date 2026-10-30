<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail\Send;

use OCA\Mcp\Tools\Mail\MailServices;
use OCA\Mcp\Tools\Mail\MailUnavailableException;
use OCA\Mcp\Tools\ToolFailure;

/**
 * Sends one message the way the Mail app's own interface does (OutboxController::create(), then send()): the
 * OutboxService saves it as an outgoing LocalMessage with its recipients, without a send time, and its sendMessage() runs
 * the Mail app's send chain on it (Sent folder check, anti-abuse, SMTP, copy to Sent, replied flag), which records the
 * outcome in the message's status. No SMTP, MIME or SQL of its own.
 *
 * Without a send time the message is not one the Mail app's background OutboxWorkerJob picks (LocalMessageMapper::findDue()
 * selects only messages with one), so nothing in the path examined sends it again later; what the user does in the
 * Outbox of Mail afterwards is theirs. A message that is not sent stays in the Outbox, as in the interface; nothing here
 * deletes it.
 *
 * A failure of the save comes before any sending is asked (no sendMessage() call): not sent. A failure of the send may
 * come after the SMTP server accepted the message (an event listener, the database update), so it has no known outcome.
 * The save and the send are called once each; the Mail app's and Horde's own fallbacks inside them stay as they are.
 */
class MailSendOutbox {
	/**
	 * Receives the Mail classes.
	 *
	 * @param MailServices $services resolver of the Mail classes
	 * @return void
	 */
	public function __construct(
		private MailServices $services,
	) {
	}

	/**
	 * Saves the message to the Outbox and sends it.
	 *
	 * @param string $userId authenticated user UID
	 * @param object $account OCA\Mail\Account the user owns
	 * @param MailSendRequest $message the message asked for
	 * @return array{status:int|null, raw:string|null, outboxId:int|null} the status the send chain recorded, the message
	 *     as the Mail app built it (null when it did not), and the id of the message in the Outbox
	 * @throws ToolFailure when the save failed (not sent) or the send failed (outcome unknown)
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function send(string $userId, object $account, MailSendRequest $message): array {
		$outbox = $this->services->outboxService($userId);
		$local = $this->services->newOutgoingMessage($userId, (int)$account->getId(), $message->subject, $message->body);
		try {
			$saved = $outbox->saveMessage($account, $local, $message->outboxRecipients('to'), $message->outboxRecipients('cc'), $message->outboxRecipients('bcc'), []);
		} catch (\Throwable $e) {
			// Never the exception's message, which can carry mailbox data.
			throw new ToolFailure(MailSendMessages::notSent(), 0, $e);
		}
		try {
			$result = $outbox->sendMessage($saved, $account);
		} catch (\Throwable $e) {
			throw new ToolFailure(MailSendMessages::uncertain(), 0, $e);
		}
		$status = $result->getStatus();
		$raw = $result->getRaw();
		$id = $saved->getId();
		return [
			'status' => $status === null ? null : (int)$status,
			'raw' => is_string($raw) && $raw !== '' ? $raw : null,
			'outboxId' => $id === null ? null : (int)$id,
		];
	}
}
