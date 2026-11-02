<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tools;

use OCA\Mcp\Mcp\Tools\{WriteTool, ToolContext, ToolDefinition};
use OCA\Mcp\Modules\Mail\Presentation\MailSchema;
use OCA\Mcp\Modules\Mail\Presentation\MailMoveMessages;
use OCA\Mcp\Modules\Mail\Moving\MoveMessageHandler;
use OCA\Mcp\Modules\Mail\Moving\MoveMessagePreview;
use OCA\Mcp\Modules\Mail\Moving\MailMoveTools;

/** Publishes `mail_move_message` and delegates its plan and effect. */
final class MoveMessage implements WriteTool {
	/**
	 * @param MoveMessageHandler $work confirmed effect
	 * @param MoveMessagePreview $plans effect-free preview
	 */
	public function __construct(private MoveMessageHandler $work, private MoveMessagePreview $plans) {}

	/** {@inheritDoc} */
	public static function definition(): ToolDefinition {
		return MailSchema::definition('mail_move_message', MailMoveTools::OPERATION, MailMoveMessages::TOOL_MOVE_MESSAGE, [
			'message_id' => MailSchema::id('Id of the message, from a listing, a search or a thread.'),
			'mailbox_id' => MailSchema::id('Id of the folder to move it to, from mail_list_mailboxes, of the same account.'),
			'plan_state' => MailSchema::planState(),
		], ['message_id', 'mailbox_id'], null, [], false);
	}

	/** {@inheritDoc} */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->work->handle($arguments, $context->userId);
	}

	/** {@inheritDoc} */
	public function preview(array $arguments, ToolContext $context): array {
		return $this->plans->plan($arguments, $context->userId);
	}
}
