<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tools;

use OCA\Mcp\Mcp\Tools\{Tool, ToolContext, ToolDefinition};
use OCA\Mcp\Modules\Mail\Presentation\MailSchema;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;
use OCA\Mcp\Modules\Mail\Reading\MailMessageReading;

/** Publishes `mail_list_messages` and delegates its read. */
final class ListMessages implements Tool {
	/**
	 * @param MailMessageReading $work read work
	 */
	public function __construct(private MailMessageReading $work) {}

	/** {@inheritDoc} */
	public static function definition(): ToolDefinition {
		return MailSchema::definition('mail_list_messages', 'read', MailMessages::TOOL_LIST_MESSAGES, [
			'mailbox_id' => MailSchema::id('Id of the folder, as mail_list_mailboxes returns it.'),
			'limit' => MailSchema::messageLimit(),
			'cursor' => MailSchema::cursor(),
		], ['mailbox_id'], ['messages'], [], true);
	}

	/** {@inheritDoc} */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->work->listMessages($arguments, $context->userId);
	}
}
