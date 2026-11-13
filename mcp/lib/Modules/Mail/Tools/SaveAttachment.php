<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tools;

use OCA\Mcp\Mcp\Tools\{WriteTool, ToolContext, ToolDefinition};
use OCA\Mcp\Modules\Mail\Presentation\{MailSchema, MailSaveMessages};
use OCA\Mcp\Modules\Mail\Writing\{SaveAttachmentHandler, SaveAttachmentPreview};

/** Publishes the opt-in binary save, after all previously published Mail tools. */
final class SaveAttachment implements WriteTool {
	/**
	 * @param SaveAttachmentHandler $work Confirmed effect.
	 * @param SaveAttachmentPreview $plans Metadata-only plan.
	 */
	public function __construct(private SaveAttachmentHandler $work, private SaveAttachmentPreview $plans) {
	}

	/** {@inheritDoc} */
	public static function definition(): ToolDefinition {
		return MailSchema::definition(
			'mail_save_attachment',
			'save',
			MailSaveMessages::TOOL,
			[
				'message_id' => MailSchema::id('Id of the message, from a listing, a search or a thread.'),
				'attachment_id' => MailSchema::attachmentId(),
				'path' => MailSchema::newFilePath(),
				'confirm_shared' => MailSchema::boolean('Confirm saving into a folder owned by someone else.'),
				'replace' => MailSchema::replace(),
				'etag' => MailSchema::replaceEtag(),
			],
			['message_id', 'attachment_id', 'path'],
			null,
			['mail.attachments'],
		);
	}

	/** {@inheritDoc} */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->work->handle($arguments, $context->userId);
	}

	/** {@inheritDoc} */
	public function preview(array $arguments, ToolContext $context): array {
		return $this->plans->plan($arguments, $context->userId);
	}
}
