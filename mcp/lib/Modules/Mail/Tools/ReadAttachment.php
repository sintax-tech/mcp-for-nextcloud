<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tools;

use OCA\Mcp\Mcp\Tools\{Tool, ToolContext, ToolDefinition};
use OCA\Mcp\Modules\Mail\Presentation\MailSchema;
use OCA\Mcp\Modules\Mail\Presentation\MailAttachmentMessages;
use OCA\Mcp\Modules\Mail\Reading\ReadAttachmentHandler;
use OCA\Mcp\Modules\Mail\Reading\MailAttachmentPreflight;

/** Publishes `mail_read_attachment` and delegates its read. */
final class ReadAttachment implements Tool {
	/**
	 * @param ReadAttachmentHandler $work read work
	 */
	public function __construct(private ReadAttachmentHandler $work) {}

	/** {@inheritDoc} */
	public static function definition(): ToolDefinition {
		return MailSchema::definition('mail_read_attachment', 'read', MailAttachmentMessages::TOOL_READ_ATTACHMENT, [
			'message_id' => MailSchema::id('Id of the message, from a listing, a search or a thread.'),
			'attachment_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => MailAttachmentPreflight::MAX_ID_LENGTH, 'description' => 'Id of the attachment, as the attachments of mail_read_message list it: a MIME part number such as "2" or "2.1".'],
			'max_chars' => MailSchema::maxChars('Most characters of the text returned (default 20000, at most 100000).'),
		], ['message_id', 'attachment_id'], ['attachments'], ['mail.attachments'], false);
	}

	/** {@inheritDoc} */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->work->handle($arguments, $context->userId);
	}
}
