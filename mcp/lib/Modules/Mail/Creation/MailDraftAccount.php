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

/** Resolves the actor's account and its usable Drafts folder. */
final class MailDraftAccount {
	/**
	 * @param MailGateway $accounts actor ownership boundary
	 * @param MailDraftFolder $folders Drafts folder resolution
	 */
	public function __construct(private MailGateway $accounts, private MailDraftFolder $folders) {}

	/**
	 * The user's own account and its Drafts folder.
	 *
	 * @param string $userId authenticated user UID
	 * @param int $accountId account id asked for
	 * @return array{object, object} the account and the folder
	 * @throws ToolFailure when the account is not the user's or has no Drafts folder
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function resolve(string $userId, int $accountId): array {
		$account = $this->accounts->ownedAccount($userId, $accountId) ?? throw new ToolFailure(MailMessages::accountNotFound());
		return [$account, $this->folders->of($userId, $account)];
	}
}
