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

/** Plans a new draft after validating recipients, Bcc support and account ownership. */
final class CreateDraftPreview {
	/**
	 * @param MailDraftAccount $accounts actor account and Drafts folder
	 * @param MailDraftPlan $plans snapshot and PlanState
	 * @param IAppManager $apps installed Mail version for Bcc preservation
	 */
	public function __construct(private MailDraftAccount $accounts, private MailDraftPlan $plans, private IAppManager $apps) {}

	/**
	 * The plan of a call without confirm.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments: account_id, to, cc, bcc, subject, body
	 * @param string $userId authenticated user UID
	 * @return array<string, mixed> the plan
	 * @throws ArgumentValidationException for a recipient, subject or text the draft cannot carry
	 * @throws ToolFailure when the draft has Bcc that the installed Mail would drop, or the account is not the user's or
	 *     has no Drafts folder
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function plan(array $arguments, string $userId): array {
		$draft = MailDraftRequest::from($arguments);
		MailDraftBcc::assertKept($draft, $this->apps);
		[$account, $drafts] = $this->accounts->resolve($userId, (int)$arguments['account_id']);
		return $this->plans->plan($userId, $account, $drafts, $draft);
	}
}
