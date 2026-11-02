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

/** Publishes `mail_search_messages` and delegates its read. */
final class SearchMessages implements Tool {
	/**
	 * @param MailMessageReading $work read work
	 */
	public function __construct(private MailMessageReading $work) {}

	/** {@inheritDoc} */
	public static function definition(): ToolDefinition {
		return MailSchema::definition('mail_search_messages', 'read', MailMessages::TOOL_SEARCH_MESSAGES, [
			'account_id' => MailSchema::id('Only this account (mail_list_accounts); omit for all of them.'),
			'mailbox_id' => MailSchema::id('Only this folder (mail_list_mailboxes); omit for every folder.'),
			'from' => MailSchema::addressFragments('Fragments of the sender name or address: a message matches when any of them matches (as the Mail app\'s own search). Different fields must all match.'),
			'to' => MailSchema::addressFragments('Fragments of the recipient name or address: a message matches when any of them matches (as the Mail app\'s own search). Different fields must all match.'),
			'cc' => MailSchema::addressFragments('Fragments of the copy recipient name or address: a message matches when any of them matches (as the Mail app\'s own search). Different fields must all match.'),
			'subject' => MailSchema::searchText('Part of the subject.'),
			'body' => MailSchema::searchText('Text of the message, searched on the mail server (one search per folder, at most 20 folders). With subject as well, a message matching either is returned.'),
			'since' => MailSchema::date('Oldest date, inclusive, as the Mail app stores it (when the mail server received the message): YYYY-MM-DD (start of that day in the user\'s time zone) or an ISO 8601 date and time with offset.'),
			'until' => MailSchema::date('Newest date, inclusive, as the Mail app stores it (when the mail server received the message): YYYY-MM-DD (end of that day in the user\'s time zone) or an ISO 8601 date and time with offset.'),
			'unread' => MailSchema::boolean('true for unread messages only, false for read ones only.'),
			'flagged' => MailSchema::boolean('true for flagged messages only, false for unflagged ones only.'),
			'has_attachments' => MailSchema::boolean('true for messages the Mail app found attachments in.'),
			'limit' => MailSchema::messageLimit(),
			'cursor' => MailSchema::cursor(),
		], [], ['messages'], [], false);
	}

	/** {@inheritDoc} */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->work->searchMessages($arguments, $context->userId);
	}
}
