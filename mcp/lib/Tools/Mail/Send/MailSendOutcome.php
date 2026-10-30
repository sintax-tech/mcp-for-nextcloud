<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail\Send;

/**
 * What the status the Mail app's send chain recorded says about a send, without claiming more than the Mail app knows.
 *
 * - STATUS_PROCESSED with the built message: the SMTP server accepted it and the copy to Sent was saved (sent).
 * - STATUS_IMAP_SENT_MAILBOX_FAIL with the built message: accepted, but the copy to Sent failed; it stays in the Outbox
 *   (sent, not copied).
 * - STATUS_SMPT_SEND_FAIL: the SMTP error was one of MailTransmission::RETRIABLE_CODES (insufficient storage, over quota,
 *   authentication required), which the Mail app counts as not sent; it stays in the Outbox (refused).
 * - STATUS_NO_SENT_MAILBOX: set by the chain's first handler, before anti-abuse and sending (not sent).
 * - Anything else is uncertain: STATUS_ERROR (any other SMTP error, a lost connection, a refused recipient among
 *   others: Horde aborts before DATA then, but the Mail app records no more than an error), STATUS_RAW, no status, any
 *   other status, and PROCESSED or IMAP_SENT_MAILBOX_FAIL without the built message (the send chain also reaches those
 *   after a send that never ran).
 * "Sent" is the acceptance by the account's SMTP server, never delivery. tests/fixtures/nextcloud-api/mail-send-path.json
 * records the statuses and codes this relies on from the pinned sources.
 */
final class MailSendOutcome {
	/** The status names this class reads, from OCA\Mail\Db\LocalMessage. */
	public const STATUSES = ['STATUS_PROCESSED', 'STATUS_IMAP_SENT_MAILBOX_FAIL', 'STATUS_SMPT_SEND_FAIL', 'STATUS_NO_SENT_MAILBOX'];

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The answer of a send.
	 *
	 * @param array{status:int|null, raw:string|null, outboxId:int|null} $result what the send chain recorded
	 * @param array<string, int> $statuses value of each name of STATUSES in the installed Mail; a name the installed Mail
	 *     lacks never matches
	 * @return array{outcome:string, sent:bool|null, copiedToSent:bool|null, message:string, messageIdHeader?:string, outboxId?:int} the answer
	 */
	public static function of(array $result, array $statuses): array {
		$is = static fn (string $name): bool => $result['status'] !== null && isset($statuses[$name]) && $result['status'] === $statuses[$name];
		$built = $result['raw'] !== null;
		[$outcome, $sent, $copied, $message] = match (true) {
			$is('STATUS_PROCESSED') && $built => ['sent', true, true, MailSendMessages::sent()],
			$is('STATUS_IMAP_SENT_MAILBOX_FAIL') && $built => ['sentNotCopied', true, false, MailSendMessages::sentNotCopied()],
			$is('STATUS_SMPT_SEND_FAIL') => ['refused', false, false, MailSendMessages::refused()],
			$is('STATUS_NO_SENT_MAILBOX') => ['notSent', false, false, MailSendMessages::notSent()],
			default => ['uncertain', null, null, MailSendMessages::uncertain()],
		};
		$answer = ['outcome' => $outcome, 'sent' => $sent, 'copiedToSent' => $copied, 'message' => $message];
		if ($built && $sent === true && preg_match('/^Message-ID:[ \t]*(<[^>\r\n]{1,995}>)/mi', (string)$result['raw'], $id) === 1) {
			$answer['messageIdHeader'] = $id[1];
		}
		if ($outcome !== 'sent' && $result['outboxId'] !== null) {
			// The message stays in the user's own Outbox of Mail.
			$answer['outboxId'] = $result['outboxId'];
		}
		return $answer;
	}
}
