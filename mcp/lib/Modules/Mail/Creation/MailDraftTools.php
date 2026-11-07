<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Creation;

use OCA\Mcp\Modules\Mail\Presentation\MailDraftMessages;
use OCA\Mcp\Modules\Mail\Integration\MailDraftStore;

use InvalidArgumentException;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Modules\Mail\Creation\CreateDraftHandler;
use OCA\Mcp\Modules\Mail\MailLinks;
use OCA\Mcp\Modules\Mail\Integration\MailGateway;
use OCA\Mcp\Modules\Mail\Integration\MailMessageGateway;
use OCA\Mcp\Modules\Mail\MailModule;
use OCA\Mcp\Modules\Mail\Integration\MailServices;
use OCA\Mcp\Mcp\Confirmation\PlanState;
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
	 * @param MailDraftHtml|null $html optional explicit pipeline for isolated callers
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
		private ?MailDraftHtml $html = null,
	) {
	}

	/**
	 * The definition of the tool; static, so it never depends on the services of an instance.
	 *
	 * @return list<array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string, grantAnyOf?:list<string>, app:string, requires?:list<string>}> published definitions in tools/list order
	 */
	public static function definitions(): array {
		return [\OCA\Mcp\Modules\Mail\Tools\CreateDraft::definition()->toArray()];
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
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure when the account is not the user's or has no Drafts folder, or Mail is not
	 *     available
	 */
	public function preview(string $name, array $arguments, string $userId): array {
		if (!self::handles($name)) { throw new InvalidArgumentException('Unknown tool'); }
		return (new CreateDraftPreview(new MailDraftAccount($this->accounts, new MailDraftFolder(new MailMessageGateway($this->services))), new MailDraftPlan($this->states, $this->grants), $this->apps, ($this->html ?? new MailDraftHtml(new \OCA\Mcp\Modules\Mail\Integration\MailDraftHtmlEngine()))))->plan($arguments, $userId);
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
	 * @throws \OCA\Mcp\Mcp\Confirmation\PlanChanged when the account, the Drafts folder or the arguments are not the approved ones
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure when no draft was created, the save has no known outcome, or Mail is not
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
		return new CreateDraftHandler(new MailDraftAccount($this->accounts, new MailDraftFolder(new MailMessageGateway($this->services))),
			new MailDraftPlan($this->states, $this->grants), new MailDraftStore($this->services), $this->links, $this->apps, ($this->html ?? new MailDraftHtml(new \OCA\Mcp\Modules\Mail\Integration\MailDraftHtmlEngine())));
	}
}
