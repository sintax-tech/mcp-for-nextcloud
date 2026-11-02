<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Editing;

use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Modules\Mail\Integration\MailFlagEvent;
use OCA\Mcp\Modules\Mail\Presentation\MailFlagMessages;
use OCA\Mcp\Modules\Mail\Editing\MailFlagPlan;
use OCA\Mcp\Modules\Mail\Editing\MailFlagRequest;
use OCA\Mcp\Modules\Mail\Integration\MailFlagStore;
use OCA\Mcp\Modules\Mail\Locating\MailMessageLocator;
use OCA\Mcp\Modules\Mail\Integration\MailServices;
use OCA\Mcp\Modules\Mail\MailUnavailableException;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;

/** Plans SetFlagsHandler without changing the server or its cache. */
final class SetFlagsPreview {
	/** @param FlagMessageContext $context per-call collaborators, including the actor timezone */
	public function __construct(private FlagMessageContext $context) {}

	/**
	 * The plan of a call without confirm.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments: message_id, seen, flagged
	 * @param string $userId authenticated user UID
	 * @return array<string, mixed> the plan
	 * @throws ArgumentValidationException when no flag is asked
	 * @throws ToolFailure when the message is not the user's
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function plan(array $arguments, string $userId): array {
		[$locator, $plans, $store, $events, $services] = $this->context->forUser($userId);
		$request = MailFlagRequest::from($arguments);
		return $plans->plan($userId, $locator->locate($userId, (int)$arguments['message_id']), $request);
	}
}
