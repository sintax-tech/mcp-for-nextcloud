<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail\Draft;

use InvalidArgumentException;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tools\Mail\Handler\CreateDraftHandler;
use OCA\Mcp\Tools\Mail\Link\MailLinks;
use OCA\Mcp\Tools\Mail\MailGateway;
use OCA\Mcp\Tools\Mail\MailMessageGateway;
use OCA\Mcp\Tools\Mail\MailModule;
use OCA\Mcp\Tools\Mail\MailServices;
use OCA\Mcp\Tools\PlanState;
use OCP\App\IAppManager;

/**
 * The draft tool of the Mail module: its definition, its plan and the delegation of a confirmed call to its handler.
 *
 * It writes, so its operation is the separate grant `mail.draft`, which starts denied like every operation but read
 * ({@see GrantPolicy::defaultGranted()}), also for policies stored before it existed: no read grant and no other Mail
 * write opens it, and it needs none of them (what they would show is left out of its plan without them). The registry
 * asks for `confirm` and shows the plan first. It carries the app id, so it is hidden while Mail is not enabled for the
 * user or is older than MCP supports.
 */
final class MailDraftTools {
	/** Grant operation of the draft tool. */
	public const OPERATION = 'draft';

	/**
	 * Receives the Mail classes, the account boundary and what the plan and the save need.
	 *
	 * @param MailServices $services resolver of the Mail classes
	 * @param MailGateway $accounts read-only access to the accounts of the Mail app
	 * @param MailLinks $links direct links into the Mail app
	 * @param PlanState $states the instance's plan states
	 * @param GrantPolicy $grants who may read what, for the plan
	 * @param IAppManager $apps app manager, for the installed Mail version ({@see MailDraftBcc})
	 * @return void
	 */
	public function __construct(
		private MailServices $services,
		private MailGateway $accounts,
		private MailLinks $links,
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
			'name' => CreateDraftHandler::TOOL,
			'description' => MailDraftMessages::TOOL_CREATE_DRAFT,
			'module' => MailModule::MODULE,
			'operation' => self::OPERATION,
			'app' => MailServices::APP_ID,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'account_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Id of the account whose Drafts folder gets the draft, from mail_list_accounts.'],
					'to' => $recipients('Recipients'),
					'cc' => $recipients('Copy recipients'),
					'bcc' => $recipients('Blind copy recipients, only with Mail ' . MailDraftBcc::MINIMUM_VERSION . ' or later (older Mail saves drafts without them, so they are refused there)'),
					'subject' => ['type' => 'string', 'maxLength' => MailDraftRequest::MAX_SUBJECT, 'description' => 'Subject, one line.'],
					'body' => ['type' => 'string', 'maxLength' => MailDraftRequest::MAX_BODY, 'description' => 'Text of the draft, plain text.'],
					PlanState::ARGUMENT => PlanState::property(),
				],
				'required' => ['account_id'],
				'additionalProperties' => false,
			],
		]];
	}

	/**
	 * Whether a tool name is the draft tool.
	 *
	 * @param string $name tool name
	 * @return bool true for mail_create_draft
	 */
	public static function handles(string $name): bool {
		return $name === CreateDraftHandler::TOOL;
	}

	/**
	 * The plan of a call without confirm.
	 *
	 * @param string $name tool name from definitions()
	 * @param array<string, mixed> $arguments validated arguments
	 * @param string $userId authenticated user UID
	 * @return array<string, mixed> the plan
	 * @throws InvalidArgumentException for a tool this class does not have, or a value the draft cannot carry
	 * @throws \OCA\Mcp\Tools\ToolFailure when the account is not the user's or has no Drafts folder, or Mail is not
	 *     available
	 */
	public function preview(string $name, array $arguments, string $userId): array {
		return $this->handler($name)->preview($arguments, $userId);
	}

	/**
	 * The confirmed draft.
	 *
	 * @param string $name tool name from definitions()
	 * @param array<string, mixed> $arguments validated arguments with confirm: true
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>} MCP result
	 * @throws InvalidArgumentException for a tool this class does not have, a call without plan_state or a value the
	 *     draft cannot carry
	 * @throws \OCA\Mcp\Tools\PlanChanged when the account, the Drafts folder or the arguments are not the approved ones
	 * @throws \OCA\Mcp\Tools\ToolFailure when no draft was created, the save has no known outcome, or Mail is not
	 *     available
	 */
	public function call(string $name, array $arguments, string $userId): array {
		return $this->handler($name)->handle($arguments, $userId);
	}

	/**
	 * The handler of the tool.
	 *
	 * @param string $name tool name
	 * @return CreateDraftHandler the handler
	 * @throws InvalidArgumentException for a tool this class does not have
	 */
	private function handler(string $name): CreateDraftHandler {
		if (!self::handles($name)) {
			throw new InvalidArgumentException('Unknown tool');
		}
		return new CreateDraftHandler($this->accounts, new MailDraftFolder(new MailMessageGateway($this->services)),
			new MailDraftPlan($this->states, $this->grants), new MailDraftStore($this->services), $this->links, $this->apps);
	}
}
