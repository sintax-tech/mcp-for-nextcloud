<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail;

use InvalidArgumentException;
use OCA\Mcp\Tools\Mail\Handler\ListAccountsHandler;
use OCA\Mcp\Tools\Mail\Handler\ListMailboxesHandler;
use OCA\Mcp\Tools\ToolGuideNotes;
use OCA\Mcp\Tools\ToolModule;

/**
 * Nextcloud Mail, read only (issue #1, first step): the accounts of the user and the folders of one account.
 *
 * The module has the single operation `read` and no write at all. Its tools carry the app id, so the registry hides
 * them and refuses a call while Mail is not enabled for the user or is older than MCP supports
 * ({@see \OCA\Mcp\Service\Compat\AppEnablement::MINIMUM_VERSIONS}). Each tool has its own handler; the module only
 * describes the tools and delegates.
 */
final class MailModule implements ToolModule, ToolGuideNotes {
	/** Grant module of the tools. */
	public const MODULE = 'mail';

	/**
	 * Receives the read-only boundary the handlers share.
	 *
	 * @param MailGateway $gateway read-only access to the Mail app
	 * @return void
	 */
	public function __construct(
		private MailGateway $gateway,
	) {
	}

	/**
	 * Behaviour notes for the tool guide.
	 *
	 * @return list<string> short behaviour notes about this module
	 */
	public function guideNotes(): array {
		return MailMessages::GUIDE_NOTES;
	}

	/**
	 * The two read tools of the module.
	 *
	 * @return list<array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string}>
	 */
	public function definitions(): array {
		return [
			[
				'name' => ListAccountsHandler::TOOL,
				'description' => MailMessages::TOOL_LIST_ACCOUNTS,
				'module' => self::MODULE,
				'operation' => 'read',
				'app' => MailServices::APP_ID,
				'inputSchema' => [
					'type' => 'object',
					'properties' => new \stdClass(),
					'additionalProperties' => false,
				],
			],
			[
				'name' => ListMailboxesHandler::TOOL,
				'description' => MailMessages::TOOL_LIST_MAILBOXES,
				'module' => self::MODULE,
				'operation' => 'read',
				'app' => MailServices::APP_ID,
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'account_id' => ['type' => 'integer', 'minimum' => 1,
							'description' => 'Id of the account, as mail_list_accounts returns it.'],
						'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ListMailboxesHandler::MAX_LIMIT,
							'default' => ListMailboxesHandler::DEFAULT_LIMIT,
							'description' => 'Maximum number of folders returned (default 200, at most 500).'],
						'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0,
							'description' => 'Number of folders to skip, from nextOffset of the previous page (default 0).'],
					],
					'required' => ['account_id'],
					'additionalProperties' => false,
				],
			],
		];
	}

	/**
	 * Delegates a call to the handler of the tool.
	 *
	 * @param string $name tool name from definitions()
	 * @param array<string, mixed> $arguments arguments already validated against the schema of the tool
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>, isError?:bool} MCP result
	 * @throws InvalidArgumentException for a tool this module does not have
	 * @throws \OCA\Mcp\Tools\ToolFailure when the account is not found or Mail is not available
	 */
	public function call(string $name, array $arguments, string $userId): array {
		return match ($name) {
			ListAccountsHandler::TOOL => (new ListAccountsHandler($this->gateway))->handle($arguments, $userId),
			ListMailboxesHandler::TOOL => (new ListMailboxesHandler($this->gateway))->handle($arguments, $userId),
			default => throw new InvalidArgumentException('Unknown tool'),
		};
	}
}
