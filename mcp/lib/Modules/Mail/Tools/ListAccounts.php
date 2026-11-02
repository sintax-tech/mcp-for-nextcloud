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
use OCA\Mcp\Modules\Mail\Reading\ListAccountsHandler;

/** Publishes `mail_list_accounts` and delegates its read. */
final class ListAccounts implements Tool {
	/**
	 * @param ListAccountsHandler $work read work
	 */
	public function __construct(private ListAccountsHandler $work) {}

	/** {@inheritDoc} */
	public static function definition(): ToolDefinition {
		return MailSchema::definition('mail_list_accounts', 'read', MailMessages::TOOL_LIST_ACCOUNTS, [

		], [], null, [], false);
	}

	/** {@inheritDoc} */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->work->handle($arguments, $context->userId);
	}
}
