<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Creation;

use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Modules\Mail\Creation\MailDraftBcc;
use OCA\Mcp\Modules\Mail\Creation\MailDraftFolder;
use OCA\Mcp\Modules\Mail\Creation\MailDraftPlan;
use OCA\Mcp\Modules\Mail\Creation\MailDraftRequest;
use OCA\Mcp\Modules\Mail\Integration\MailDraftStore;
use OCA\Mcp\Modules\Mail\MailLinks;
use OCA\Mcp\Modules\Mail\Integration\MailGateway;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;
use OCA\Mcp\Modules\Mail\MailUnavailableException;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\App\IAppManager;

/**
 * Backs `mail_create_draft`: one new draft in the Drafts folder of one of the user's own accounts, never sent.
 *
 * A call without `confirm` gets the plan ({@see MailDraftPlan}) without contacting the mail server. A confirmed call
 * resolves the account (only the user's own: another user's account, or one delegated to the user, reads like a missing
 * one) and its Drafts folder again ({@see MailDraftFolder}), requires the `plan_state` of the plan and answers with the
 * new plan when the account, the folder or the arguments are not the approved ones ({@see PlanChanged}). A draft with
 * Bcc is refused, before the plan and before the save, on a Mail release that saves drafts without it
 * ({@see MailDraftBcc}). Then the Mail app's own draft save writes it ({@see MailDraftStore}). The draft joins the Mail cache at the Mail app's next
 * synchronization of the folder. Each confirmed call creates one more draft; after an uncertain answer the draft may
 * exist, so the answer says to look in Drafts and not to repeat. The tool adds no retry of its own; the Mail app's and
 * Horde's own fallbacks inside the save stay as they are ({@see MailDraftStore}).
 */
final class CreateDraftHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = MailDraftPlan::TOOL;

	/**
	 * Receives the account boundary, the Drafts folder, the plan, the save and the links.
	 *
	 * @param MailDraftAccount $accounts actor account and Drafts folder
	 * @param MailDraftPlan $plans the plan and its state
	 * @param MailDraftStore $store saves the draft through the Mail app
	 * @param MailLinks $links direct links into the Mail app, for the Drafts folder
	 * @param MailDraftHtml $html restricted draft sanitization pipeline
	 * @param IAppManager $apps app manager, for the installed Mail version
	 * @return void
	 */
	public function __construct(
		private MailDraftAccount $accounts,
		private MailDraftPlan $plans,
		private MailDraftStore $store,
		private MailLinks $links,
		private IAppManager $apps,
		private MailDraftHtml $html,
	) {
	}



	/**
	 * The confirmed draft.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments with plan_state
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>} MCP result
	 * @throws ArgumentValidationException when plan_state is missing, or for a value the draft cannot carry
	 * @throws PlanChanged when the account, the Drafts folder or the arguments are not the ones of the approved plan
	 * @throws ToolFailure when the draft has Bcc that the installed Mail would drop, the account is not the user's or has
	 *     no Drafts folder, no draft was created, or the save has no known outcome
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function handle(array $arguments, string $userId): array {
		PlanState::require($arguments);
		$draft = MailDraftRequest::from($arguments);
		// Checked again: Mail may have been replaced by an older release since the plan.
		MailDraftBcc::assertKept($draft, $this->apps);
		[$account, $drafts] = $this->accounts->resolve($userId, (int)$arguments['account_id']);
		$draft = $draft->sanitized($this->html);
		if (!PlanState::matches($this->plans->state($userId, $account, $drafts, $draft), $arguments)) {
			throw new PlanChanged($this->plans->plan($userId, $account, $drafts, $draft, true));
		}
		$uid = $this->store->create($userId, $account, $draft);
		return ToolResult::json([
			'created' => true,
			'accountId' => (int)$account->getId(),
			'draftsMailboxId' => (int)$drafts->getId(),
			'draftsUrl' => $this->links->mailbox((int)$drafts->getId()),
			'uid' => $uid,
			'sent' => false,
		]);
	}


}
