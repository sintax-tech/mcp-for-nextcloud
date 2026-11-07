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
use OCA\Mcp\Modules\Mail\Reading\ListMailboxesHandler;

/** Publishes `mail_list_mailboxes` and delegates its read. */
final class ListMailboxes implements Tool {
	/**
	 * @param ListMailboxesHandler $work read work
	 */
	public function __construct(private ListMailboxesHandler $work) {}

	/** {@inheritDoc} */
	public static function definition(): ToolDefinition {
		return MailSchema::definition('mail_list_mailboxes', 'read', MailMessages::TOOL_LIST_MAILBOXES, [
			'account_id' => MailSchema::id('Id of the account, as mail_list_accounts returns it.'),
			'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ListMailboxesHandler::MAX_LIMIT, 'default' => ListMailboxesHandler::DEFAULT_LIMIT, 'description' => 'Maximum number of folders returned (default 200, at most 500).'],
			'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'Number of folders to skip, from nextOffset of the previous page (default 0).'],
		], ['account_id'], null, [], false);
	}

	/** {@inheritDoc} */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->work->handle($arguments, $context->userId);
	}
}
