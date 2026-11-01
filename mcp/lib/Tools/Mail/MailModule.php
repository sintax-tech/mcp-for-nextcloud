<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail;

use InvalidArgumentException;
use OCA\Mcp\Tools\Mail\Flag\MailFlagMessages;
use OCA\Mcp\Tools\Mail\Flag\MailFlagPlanRenderer;
use OCA\Mcp\Tools\Mail\Flag\MailFlagTools;
use OCA\Mcp\Tools\Mail\Handler\ListAccountsHandler;
use OCA\Mcp\Tools\Mail\Handler\ListMailboxesHandler;
use OCA\Mcp\Tools\Mail\Link\MailLinks;
use OCA\Mcp\Tools\Mail\Draft\MailDraftMessages;
use OCA\Mcp\Tools\Mail\Draft\MailDraftPlanRenderer;
use OCA\Mcp\Tools\Mail\Draft\MailDraftTools;
use OCA\Mcp\Tools\Mail\Move\MailMoveMessages;
use OCA\Mcp\Tools\Mail\Move\MailMovePlanRenderer;
use OCA\Mcp\Tools\Mail\Move\MailMoveTools;
use OCA\Mcp\Tools\Mail\Send\MailSendMessages;
use OCA\Mcp\Tools\Mail\Send\MailSendPlanRenderer;
use OCA\Mcp\Tools\Mail\Send\MailSendTools;
use OCA\Mcp\Mcp\Tools\PreviewsWrites;
use OCA\Mcp\Mcp\Tools\RendersPlans;
use OCA\Mcp\Mcp\Guide\ToolGuideNotes;
use OCA\Mcp\Mcp\Tools\ToolModule;

/**
 * Nextcloud Mail, read only (issue #1): the accounts of the user, the folders of one account, with the separate
 * `messages` grant the messages themselves ({@see MailMessageTools}) and with the separate `attachments` grant the text
 * of an attachment ({@see MailAttachmentTools}); with the separate `flags` grant it marks a message read or unread and
 * flagged or not ({@see MailFlagTools}), with the separate `move` grant it moves a message to another folder of its
 * account ({@see MailMoveTools}), with the separate `draft` grant it saves a new draft, never sent
 * ({@see MailDraftTools}), and with the separate `send` grant it sends a new message through the Mail app
 * ({@see MailSendTools}).
 *
 * Its read operations are `read` (accounts and folders), `messages` (listing, search, reading and threads) and
 * `attachments` (the text of one attachment); its writes are `flags`, `move`, `draft` and `send`, planned first and confirmed by the user. Its tools carry the app
 * id, so the registry hides them and refuses a call while Mail is not enabled for the user or is older than MCP supports
 * ({@see \OCA\Mcp\Platform\Compat\AppEnablement::MINIMUM_VERSIONS}). Each tool has its own handler; the module only
 * describes the tools and delegates.
 */
final class MailModule implements ToolModule, ToolGuideNotes, PreviewsWrites, RendersPlans {
	/** Grant module of the tools. */
	public const MODULE = 'mail';

	/**
	 * Receives the read-only boundary of accounts and folders, the message tools, the attachment tool, the Mail app links,
	 * the flag tool, the move tool, the draft tool and the send tool.
	 *
	 * @param MailGateway $gateway read-only access to the Mail app
	 * @param MailMessageTools $messageTools the message tools, behind the `messages` grant
	 * @param MailAttachmentTools $attachmentTools the attachment tool, behind the `attachments` grant
	 * @param MailLinks $links direct links into the Mail app
	 * @param MailFlagTools $flagTools the flag tool, behind the `flags` grant
	 * @param MailMoveTools $moveTools the move tool, behind the `move` grant
	 * @param MailDraftTools $draftTools the draft tool, behind the `draft` grant
	 * @param MailSendTools $sendTools the send tool, behind the `send` grant
	 * @return void
	 */
	public function __construct(
		private MailGateway $gateway,
		private MailMessageTools $messageTools,
		private MailAttachmentTools $attachmentTools,
		private MailLinks $links,
		private MailFlagTools $flagTools,
		private MailMoveTools $moveTools,
		private MailDraftTools $draftTools,
		private MailSendTools $sendTools,
	) {
	}

	/**
	 * Behaviour notes for the tool guide.
	 *
	 * @return list<string> short behaviour notes about this module
	 */
	public function guideNotes(): array {
		return [...MailMessages::GUIDE_NOTES, ...MailAttachmentMessages::GUIDE_NOTES, ...MailFlagMessages::GUIDE_NOTES, ...MailMoveMessages::GUIDE_NOTES, ...MailDraftMessages::GUIDE_NOTES, ...MailSendMessages::GUIDE_NOTES];
	}

	/**
	 * The read tools of the module: accounts and folders, the message tools, then the attachment tool when the installed
	 * Mail supports it.
	 *
	 * @return list<array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, grantAnyOf?:list<string>, app:string}>
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
			...MailMessageTools::definitions(),
			// A module enumerated without its constructor has no app manager to tell the Mail version: the tool is left
			// out then, fail-closed, as the Deck dependency tools are.
			...(isset($this->attachmentTools) ? $this->attachmentTools->definitions() : []),
			...MailFlagTools::definitions(),
			...MailMoveTools::definitions(),
			...MailDraftTools::definitions(),
			...MailSendTools::definitions(),
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
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure when the account is not found or Mail is not available
	 */
	public function call(string $name, array $arguments, string $userId): array {
		return match ($name) {
			ListAccountsHandler::TOOL => (new ListAccountsHandler($this->gateway))->handle($arguments, $userId),
			ListMailboxesHandler::TOOL => (new ListMailboxesHandler($this->gateway, $this->links))->handle($arguments, $userId),
			default => match (true) {
				MailMessageTools::handles($name) => $this->messageTools->call($name, $arguments, $userId),
				MailAttachmentTools::handles($name) => $this->attachmentTools->call($name, $arguments, $userId),
				MailFlagTools::handles($name) => $this->flagTools->call($name, $arguments, $userId),
				MailMoveTools::handles($name) => $this->moveTools->call($name, $arguments, $userId),
				MailDraftTools::handles($name) => $this->draftTools->call($name, $arguments, $userId),
				MailSendTools::handles($name) => $this->sendTools->call($name, $arguments, $userId),
				default => throw new InvalidArgumentException('Unknown tool'),
			},
		};
	}

	/**
	 * The plan of a write without confirm: the flag tool, the move tool, the draft tool or the send tool.
	 *
	 * @param string $name tool name of a writing definition
	 * @param array<string, mixed> $arguments validated arguments
	 * @param string $userId authenticated user UID
	 * @return array<string, mixed> the plan
	 * @throws InvalidArgumentException for a tool that is not a write of this module
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure when the message or the account is not the user's, or Mail is not available
	 */
	public function preview(string $name, array $arguments, string $userId): array {
		return match (true) {
			MailMoveTools::handles($name) => $this->moveTools->preview($name, $arguments, $userId),
			MailDraftTools::handles($name) => $this->draftTools->preview($name, $arguments, $userId),
			MailSendTools::handles($name) => $this->sendTools->preview($name, $arguments, $userId),
			default => $this->flagTools->preview($name, $arguments, $userId),
		};
	}

	/**
	 * The readable body of a plan.
	 *
	 * @param string $tool tool name
	 * @param array<string, mixed> $plan the plan returned by preview()
	 * @return string|null Markdown body, or null for the generic one
	 */
	public function renderPlan(string $tool, array $plan): ?string {
		return match (true) {
			MailFlagTools::handles($tool) => (new MailFlagPlanRenderer())->render($plan),
			MailMoveTools::handles($tool) => (new MailMovePlanRenderer())->render($plan),
			MailDraftTools::handles($tool) => (new MailDraftPlanRenderer())->render($plan),
			MailSendTools::handles($tool) => (new MailSendPlanRenderer())->render($plan),
			default => null,
		};
	}
}
