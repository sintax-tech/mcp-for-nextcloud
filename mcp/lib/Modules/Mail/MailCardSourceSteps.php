<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail;

use OCA\Mcp\Mcp\Tools\Discovery\ToolAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use OCA\Mcp\Modules\Mail\Presentation\MailAttachmentMessages;
use OCA\Mcp\Modules\Mail\Reading\MailAttachmentPreflight;
use OCA\Mcp\Modules\Mail\Reading\MailMessageReading;
use OCA\Mcp\Modules\Mail\Tools\ReadMessage;
use OCA\Mcp\Platform\Workflows\WorkflowMessages;
use OCA\Mcp\Platform\MailCards\MailCardSource;

/**
 * The source of a mail card, through the very work classes of mail_read_message.
 *
 * The read is checked as the registry checks mail_read_message (grant mail.messages, Guests and the Mail app by
 * {@see ToolAccess}, then the published schema); the work classes resolve the message through the actor's own
 * accounts, check its size and UID generation and read the body with BODY.PEEK as they always do. Only the fields a
 * card plan shows are kept: the recipients, the other attachments and the raw headers are left out.
 */
final class MailCardSourceSteps implements MailCardSource {
	/**
	 * @param ToolAccess $access the registry's own decision on grant, Guests and app
	 * @param MailMessageReading $reading the read work of mail_read_message
	 */
	public function __construct(
		private ToolAccess $access,
		private MailMessageReading $reading,
	) {
	}

	/** {@inheritDoc} */
	public function read(string $userId, int $messageId, string $attachmentId, int $excerptChars): array {
		$definition = ReadMessage::definition();
		if (!$this->access->allowed($definition->toArray(), $userId)) {
			throw new ToolFailure(WorkflowMessages::notAllowed($definition->name));
		}
		$arguments = ArgumentValidator::validate($definition->inputSchema, ['message_id' => $messageId, 'max_chars' => $excerptChars]);
		if (!MailAttachmentPreflight::validId($attachmentId)) {
			throw new ToolFailure(MailAttachmentMessages::attachmentNotFound());
		}
		$payload = json_decode($this->reading->readMessage($arguments, $userId)['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
		$message = $payload['message'];
		foreach ($message['attachments'] as $attachment) {
			if ($attachment['id'] === $attachmentId) {
				return [
					'untrustedMailData' => true,
					'notice' => $payload['notice'],
					'message' => [
						'id' => $message['id'],
						'subject' => $message['subject'],
						'from' => $message['from'],
						'date' => $message['date'],
						'url' => $message['url'],
						'body' => $message['body'],
					],
					'attachment' => $attachment,
				];
			}
		}
		// Only the attachments mail_read_message lists can be named: one it does not list is not offered.
		throw new ToolFailure(MailAttachmentMessages::attachmentNotFound());
	}
}
