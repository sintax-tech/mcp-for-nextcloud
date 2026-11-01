<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail\Draft;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\Mail\MailModule;
use OCA\Mcp\Tools\Mail\MailRows;
use OCA\Mcp\Mcp\Confirmation\PlanState;

/**
 * The plan of a draft, built without contacting the mail server, and its {@see PlanState}.
 *
 * The plan shows the draft as it will be saved: the recipients, the subject and the text, which the caller wrote; the
 * account and the Drafts folder by their ids, and their address and path only for a user with `mail.read`, so the draft
 * grant alone reads nothing of the mailbox through a plan. It always says that nothing is sent and that each confirmation
 * creates one more draft.
 *
 * The plan state covers the account and the Drafts folder (id and path) and the whole draft: a confirmed call whose
 * account, folder or arguments differ gets the new plan instead of a draft. It is no receipt: confirming the same plan
 * again creates another draft.
 */
class MailDraftPlan {
	/** MCP tool name of the plan. */
	public const TOOL = 'mail_create_draft';

	/**
	 * Receives the plan states and the grants.
	 *
	 * @param PlanState $states the instance's plan states
	 * @param GrantPolicy $grants who may read what, for the account address and the folder path
	 * @return void
	 */
	public function __construct(
		private PlanState $states,
		private GrantPolicy $grants,
	) {
	}

	/**
	 * The plan.
	 *
	 * @param string $userId authenticated user UID
	 * @param object $account OCA\Mail\Account the user owns
	 * @param object $drafts OCA\Mail\Db\Mailbox, the account's Drafts folder
	 * @param MailDraftRequest $draft the draft asked for
	 * @param bool $changed whether a confirmed call found this instead of the approved plan: a warning says so first
	 * @return array<string, mixed> the plan
	 */
	public function plan(string $userId, object $account, object $drafts, MailDraftRequest $draft, bool $changed = false): array {
		$target = ['accountId' => (int)$account->getId(), 'draftsMailboxId' => (int)$drafts->getId()];
		if ($this->grants->granted($userId, MailModule::MODULE, 'read')) {
			$target['account'] = MailRows::text((string)$account->getEmail());
			$target['draftsPath'] = MailRows::text((string)$drafts->getName());
		}
		return [
			'action' => self::TOOL,
			'target' => $target,
			'draft' => $draft->recipients + ['subject' => $draft->subject, 'body' => $draft->body],
			'message' => CommonMessages::planNothingChanged(),
			'notes' => [MailDraftMessages::notSent(), MailDraftMessages::eachConfirmCreates()],
			PlanState::ARGUMENT => $this->state($userId, $account, $drafts, $draft),
		] + ($changed ? ['warnings' => [['message' => MailDraftMessages::planChanged()]]] : []);
	}

	/**
	 * The plan state of a draft.
	 *
	 * @param string $userId authenticated user UID
	 * @param object $account OCA\Mail\Account the user owns
	 * @param object $drafts OCA\Mail\Db\Mailbox, the account's Drafts folder
	 * @param MailDraftRequest $draft the draft asked for
	 * @return string the state
	 */
	public function state(string $userId, object $account, object $drafts, MailDraftRequest $draft): string {
		return $this->states->of(self::TOOL, $userId, [
			'account' => (int)$account->getId(),
			'drafts' => (int)$drafts->getId(),
			'draftsPath' => (string)$drafts->getName(),
			'recipients' => $draft->recipients,
			'subject' => $draft->subject,
			'body' => hash('sha256', $draft->body),
		]);
	}
}
