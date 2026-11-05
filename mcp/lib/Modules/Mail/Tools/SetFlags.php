<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tools;

use OCA\Mcp\Mcp\Tools\{WriteTool, ToolContext, ToolDefinition};
use OCA\Mcp\Modules\Mail\Presentation\MailSchema;
use OCA\Mcp\Modules\Mail\Presentation\MailFlagMessages;
use OCA\Mcp\Modules\Mail\Editing\SetFlagsHandler;
use OCA\Mcp\Modules\Mail\Editing\SetFlagsPreview;
use OCA\Mcp\Modules\Mail\Editing\MailFlagTools;

/** Publishes `mail_set_flags` and delegates its plan and effect. */
final class SetFlags implements WriteTool {
	/**
	 * @param SetFlagsHandler $work confirmed effect
	 * @param SetFlagsPreview $plans effect-free preview
	 */
	public function __construct(private SetFlagsHandler $work, private SetFlagsPreview $plans) {}

	/** {@inheritDoc} */
	public static function definition(): ToolDefinition {
		return MailSchema::definition('mail_set_flags', MailFlagTools::OPERATION, MailFlagMessages::TOOL_SET_FLAGS, [
			'message_id' => MailSchema::id('Id of the message, from a listing, a search or a thread.'),
			'seen' => MailSchema::boolean('true to mark the message read, false to mark it unread; omit to leave it.'),
			'flagged' => MailSchema::boolean('true to flag the message, false to remove the flag; omit to leave it.'),
			'plan_state' => MailSchema::planState(),
		], ['message_id'], null, [], false);
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
