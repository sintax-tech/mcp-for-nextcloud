<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\MailCards;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * The source of a mail card: one message of the actor and one of its attachments, read for the plan. Implemented by the
 * Mail module through the work classes of mail_read_message; without a binding the container fails.
 *
 * Every call checks again, for the actor, everything mail_read_message is checked for: the grant mail.messages,
 * Guests, the Mail app, the arguments and the actor's own accounts. The body is read with BODY.PEEK, so the message
 * stays unread if it was; nothing is written.
 */
interface MailCardSource {
	/**
	 * The sender, subject, date, Mail link and a bounded excerpt of the body of one message, with the listed metadata
	 * of one of its attachments. Everything but the ids was written by whoever sent the message.
	 *
	 * @param string $userId the actor, owner of the mail account
	 * @param int $messageId the message, as mail_read_message takes it
	 * @param string $attachmentId the attachment, as mail_read_message lists it
	 * @param int $excerptChars most characters of the body returned, within the max_chars of mail_read_message
	 * @return array{untrustedMailData: true, notice: string, message: array{id: int, subject: string, from: array<string, mixed>, date: string, url: string|null, body: array<string, mixed>}, attachment: array{id: string, name: string|null, mimeType: string, size: int|null}}
	 *     the message and the attachment, with nothing written
	 * @throws ToolFailure when the read is not allowed for the actor, the message is not the actor's or the attachment is not listed
	 * @throws \InvalidArgumentException on arguments mail_read_message refuses
	 */
	public function read(string $userId, int $messageId, string $attachmentId, int $excerptChars): array;
}
