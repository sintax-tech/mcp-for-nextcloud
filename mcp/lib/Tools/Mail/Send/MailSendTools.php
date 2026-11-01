<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail\Send;

use InvalidArgumentException;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tools\Mail\Draft\MailDraftRequest;
use OCA\Mcp\Tools\Mail\Handler\SendMessageHandler;
use OCA\Mcp\Tools\Mail\MailGateway;
use OCA\Mcp\Tools\Mail\MailMessageGateway;
use OCA\Mcp\Tools\Mail\MailModule;
use OCA\Mcp\Tools\Mail\MailServices;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCP\App\IAppManager;

/**
 * The send tool of the Mail module: its definition, its plan and the delegation of a confirmed call to its handler.
 *
 * It writes, and cannot be undone, so its operation is the separate grant `mail.send`, which starts denied like every
 * operation but read ({@see GrantPolicy::defaultGranted()}), also for policies stored before it existed: no read grant and
 * no other Mail write opens it, and it needs none of them (what they would show is left out of its plan without them).
 * The registry asks for `confirm` and shows the plan first. It carries the app id, so it is hidden while Mail is not
 * enabled for the user or is older than MCP supports.
 */
final class MailSendTools {
	/** Grant operation of the send tool. */
	public const OPERATION = 'send';

	/**
	 * Receives the Mail classes, the account boundary and what the plan needs.
	 *
	 * @param MailServices $services resolver of the Mail classes
	 * @param MailGateway $accounts read-only access to the accounts of the Mail app
	 * @param PlanState $states the instance's plan states
	 * @param GrantPolicy $grants who may read what, for the plan
	 * @param IAppManager $apps app manager, for the installed Mail version of the plan's Bcc note
	 * @return void
	 */
	public function __construct(
		private MailServices $services,
		private MailGateway $accounts,
		private PlanState $states,
		private GrantPolicy $grants,
		private IAppManager $apps,
	) {
	}

	/**
	 * The definition of the tool; static, so it never depends on the services of an instance.
	 *
	 * @return list<array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string}>
	 */
	public static function definitions(): array {
		$recipients = static fn (string $what): array => [
			'type' => 'array',
			'maxItems' => MailDraftRequest::MAX_RECIPIENTS,
			'items' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 254],
			'description' => $what . ': plain addresses such as name@example.com, without display names; omit for none.',
		];
		return [[
			'name' => SendMessageHandler::TOOL,
			'description' => MailSendMessages::TOOL_SEND_MESSAGE,
			'module' => MailModule::MODULE,
			'operation' => self::OPERATION,
			'app' => MailServices::APP_ID,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'account_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Id of the account to send from, from mail_list_accounts.'],
					'to' => $recipients('Recipients'),
					'cc' => $recipients('Copy recipients'),
					'bcc' => $recipients('Blind copy recipients, given to the SMTP server only, never in the headers it receives'),
					'subject' => ['type' => 'string', 'maxLength' => MailDraftRequest::MAX_SUBJECT, 'description' => 'Subject, one line.'],
					'body' => ['type' => 'string', 'maxLength' => MailDraftRequest::MAX_BODY, 'description' => 'Text of the message, plain text.'],
					PlanState::ARGUMENT => PlanState::property(),
				],
				'required' => ['account_id'],
				'additionalProperties' => false,
			],
		]];
	}

	/**
	 * Whether a tool name is the send tool.
	 *
	 * @param string $name tool name
	 * @return bool true for mail_send_message
	 */
	public static function handles(string $name): bool {
		return $name === SendMessageHandler::TOOL;
	}

	/**
	 * The plan of a call without confirm.
	 *
	 * @param string $name tool name from definitions()
	 * @param array<string, mixed> $arguments validated arguments
	 * @param string $userId authenticated user UID
	 * @return array<string, mixed> the plan
	 * @throws InvalidArgumentException for a tool this class does not have, or a value the message cannot carry
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure when the account is not the user's or has no Sent folder, or Mail is not
	 *     available
	 */
	public function preview(string $name, array $arguments, string $userId): array {
		return $this->handler($name)->preview($arguments, $userId);
	}

	/**
	 * The confirmed send.
	 *
	 * @param string $name tool name from definitions()
	 * @param array<string, mixed> $arguments validated arguments with confirm: true
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>} MCP result
	 * @throws InvalidArgumentException for a tool this class does not have, a call without plan_state or a value the
	 *     message cannot carry
	 * @throws \OCA\Mcp\Mcp\Confirmation\PlanChanged when the account, the sender, the Sent folder or the arguments are not the
	 *     approved ones
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure when the message was not sent for certain or the outcome is unknown, or Mail is
	 *     not available
	 */
	public function call(string $name, array $arguments, string $userId): array {
		return $this->handler($name)->handle($arguments, $userId);
	}

	/**
	 * The handler of the tool.
	 *
	 * @param string $name tool name
	 * @return SendMessageHandler the handler
	 * @throws InvalidArgumentException for a tool this class does not have
	 */
	private function handler(string $name): SendMessageHandler {
		if (!self::handles($name)) {
			throw new InvalidArgumentException('Unknown tool');
		}
		return new SendMessageHandler(new MailSendAccount($this->accounts, new MailMessageGateway($this->services)),
			new MailSendPlan($this->states, $this->grants, $this->apps), new MailSendOutbox($this->services), $this->services);
	}
}
