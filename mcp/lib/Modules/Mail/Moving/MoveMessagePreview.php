<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Moving;

use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Modules\Mail\MailLinks;
use OCA\Mcp\Modules\Mail\Locating\MailMessageLocator;
use OCA\Mcp\Modules\Mail\Integration\MailServices;
use OCA\Mcp\Modules\Mail\MailUnavailableException;
use OCA\Mcp\Modules\Mail\Moving\MailMoveDestination;
use OCA\Mcp\Modules\Mail\Presentation\MailMoveMessages;
use OCA\Mcp\Modules\Mail\Moving\MailMovePlan;
use OCA\Mcp\Modules\Mail\Integration\MailMoveStore;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\EventDispatcher\IEventDispatcher;

/** Plans MoveMessageHandler without changing the server or its cache. */
final class MoveMessagePreview {
	/** @param MoveMessageContext $context per-call collaborators, including the actor timezone */
	public function __construct(private MoveMessageContext $context) {}

	/**
	 * The plan of a call without confirm.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments: message_id, mailbox_id
	 * @param string $userId authenticated user UID
	 * @return array<string, mixed> the plan
	 * @throws ToolFailure when the message or the folder is not the user's, or the folder cannot be used
	 * @throws MailUnavailableException when Mail is not available for the user
	 */
	public function plan(array $arguments, string $userId): array {
		[$locator, $destinations, $plans, $store, $links, $events, $services] = $this->context->forUser($userId);
		$found = $locator->locate($userId, (int)$arguments['message_id']);
		return $plans->plan($userId, $found, $destinations->resolve($userId, $found, (int)$arguments['mailbox_id']));
	}
}
