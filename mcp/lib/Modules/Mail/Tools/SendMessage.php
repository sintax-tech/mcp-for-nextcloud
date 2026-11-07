<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tools;

use OCA\Mcp\Mcp\Tools\{WriteTool, ToolContext, ToolDefinition};
use OCA\Mcp\Modules\Mail\Presentation\MailSchema;
use OCA\Mcp\Modules\Mail\Presentation\MailSendMessages;
use OCA\Mcp\Modules\Mail\Sending\SendMessageHandler;
use OCA\Mcp\Modules\Mail\Sending\SendMessagePreview;
use OCA\Mcp\Modules\Mail\Sending\MailSendTools;

/** Publishes `mail_send_message` and delegates its plan and effect. */
final class SendMessage implements WriteTool {
	/**
	 * @param SendMessageHandler $work confirmed effect
	 * @param SendMessagePreview $plans effect-free preview
	 */
	public function __construct(private SendMessageHandler $work, private SendMessagePreview $plans) {}

	/** {@inheritDoc} */
	public static function definition(): ToolDefinition {
		return MailSchema::definition('mail_send_message', MailSendTools::OPERATION, MailSendMessages::TOOL_SEND_MESSAGE, [
			'account_id' => MailSchema::id('Id of the account to send from, from mail_list_accounts.'),
			'to' => MailSchema::recipients('Recipients: plain addresses such as name@example.com, without display names; omit for none.'),
			'cc' => MailSchema::recipients('Copy recipients: plain addresses such as name@example.com, without display names; omit for none.'),
			'bcc' => MailSchema::recipients('Blind copy recipients, given to the SMTP server only, never in the headers it receives: plain addresses such as name@example.com, without display names; omit for none.'),
			'subject' => MailSchema::subject(),
			'body' => MailSchema::body('Text of the message, plain text.'),
			'plan_state' => MailSchema::planState(),
		], ['account_id'], null, [], false);
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
