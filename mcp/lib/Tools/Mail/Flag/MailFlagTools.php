<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail\Flag;

use InvalidArgumentException;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\UserTimezone;
use OCA\Mcp\Tools\Mail\Handler\SetFlagsHandler;
use OCA\Mcp\Tools\Mail\Link\MailLinks;
use OCA\Mcp\Tools\Mail\MailGateway;
use OCA\Mcp\Tools\Mail\MailMessageGateway;
use OCA\Mcp\Tools\Mail\MailMessageLocator;
use OCA\Mcp\Tools\Mail\MailMessageRows;
use OCA\Mcp\Tools\Mail\MailModule;
use OCA\Mcp\Tools\Mail\MailServices;
use OCA\Mcp\Tools\PlanState;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * The flag tool of the Mail module: its definition, its plan and the delegation of a confirmed call to its handler.
 *
 * It writes, so its operation is the separate grant `mail.flags`, which starts denied like every operation but read
 * ({@see GrantPolicy::defaultGranted()}), also for policies stored before it existed: neither `mail.read`,
 * `mail.messages` nor `mail.attachments` opens it, and it needs none of them (what they would show is left out of its
 * plan without them). The registry asks for `confirm` and shows the plan first. It carries the app id, so it is hidden
 * while Mail is not enabled for the user or is older than MCP supports.
 */
final class MailFlagTools {
	/** Grant operation of the flag tool. */
	public const OPERATION = 'flags';

	/**
	 * Receives the Mail classes, the account boundary and what the plan and the change need.
	 *
	 * @param MailServices $services resolver of the Mail classes
	 * @param MailGateway $accounts read-only access to the accounts and folders of the Mail app
	 * @param UserTimezone $timezones time zone of each user, for the date in the plan
	 * @param MailLinks $links direct links into the Mail app, for the message row
	 * @param PlanState $states the instance's plan states
	 * @param GrantPolicy $grants who may read what, for the plan
	 * @param IEventDispatcher $events event dispatcher, for the Mail app's flag event
	 * @return void
	 */
	public function __construct(
		private MailServices $services,
		private MailGateway $accounts,
		private UserTimezone $timezones,
		private MailLinks $links,
		private PlanState $states,
		private GrantPolicy $grants,
		private IEventDispatcher $events,
	) {
	}

	/**
	 * The definition of the tool; static, so it never depends on the services of an instance.
	 *
	 * @return list<array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, app:string}>
	 */
	public static function definitions(): array {
		return [[
			'name' => SetFlagsHandler::TOOL,
			'description' => MailFlagMessages::TOOL_SET_FLAGS,
			'module' => MailModule::MODULE,
			'operation' => self::OPERATION,
			'app' => MailServices::APP_ID,
			'inputSchema' => [
				'type' => 'object',
				'properties' => [
					'message_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Id of the message, from a listing, a search or a thread.'],
					'seen' => ['type' => 'boolean', 'description' => 'true to mark the message read, false to mark it unread; omit to leave it.'],
					'flagged' => ['type' => 'boolean', 'description' => 'true to flag the message, false to remove the flag; omit to leave it.'],
					PlanState::ARGUMENT => PlanState::property(),
				],
				'required' => ['message_id'],
				'additionalProperties' => false,
			],
		]];
	}

	/**
	 * Whether a tool name is the flag tool.
	 *
	 * @param string $name tool name
	 * @return bool true for mail_set_flags
	 */
	public static function handles(string $name): bool {
		return $name === SetFlagsHandler::TOOL;
	}

	/**
	 * The plan of a call without confirm.
	 *
	 * @param string $name tool name from definitions()
	 * @param array<string, mixed> $arguments validated arguments
	 * @param string $userId authenticated user UID
	 * @return array<string, mixed> the plan
	 * @throws InvalidArgumentException for a tool this class does not have, or a call that asks for no flag
	 * @throws \OCA\Mcp\Tools\ToolFailure when the message is not the user's, or Mail is not available
	 */
	public function preview(string $name, array $arguments, string $userId): array {
		return $this->handler($name, $userId)->preview($arguments, $userId);
	}

	/**
	 * The confirmed change.
	 *
	 * @param string $name tool name from definitions()
	 * @param array<string, mixed> $arguments validated arguments with confirm: true
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>} MCP result
	 * @throws InvalidArgumentException for a tool this class does not have, or a call without flag or plan_state
	 * @throws \OCA\Mcp\Tools\PlanChanged when the message or the arguments are not the approved ones
	 * @throws \OCA\Mcp\Tools\ToolFailure when the message is not the user's, changed, is gone, the server failed or
	 *     Mail is not available
	 */
	public function call(string $name, array $arguments, string $userId): array {
		return $this->handler($name, $userId)->handle($arguments, $userId);
	}

	/**
	 * The handler of the tool for one user.
	 *
	 * @param string $name tool name
	 * @param string $userId authenticated user UID
	 * @return SetFlagsHandler the handler
	 * @throws InvalidArgumentException for a tool this class does not have
	 */
	private function handler(string $name, string $userId): SetFlagsHandler {
		if (!self::handles($name)) {
			throw new InvalidArgumentException('Unknown tool');
		}
		$locator = new MailMessageLocator($this->accounts, new MailMessageGateway($this->services));
		$plans = new MailFlagPlan($this->states, $this->grants, new MailMessageRows($this->timezones->forUser($userId), $this->links));
		return new SetFlagsHandler($locator, $plans, new MailFlagStore($this->services), new MailFlagEvent($this->events), $this->services);
	}
}
