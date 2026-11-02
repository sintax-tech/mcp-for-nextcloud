<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Tools;

use OCA\Mcp\Mcp\Tools\{Tool, ToolContext, ToolDefinition};
use OCA\Mcp\Modules\Mail\Presentation\MailSchema;
use OCA\Mcp\Modules\Mail\Presentation\MailMessages;
use OCA\Mcp\Modules\Mail\Reading\MailMessageReading;

/** Publishes `mail_read_message` and delegates its read. */
final class ReadMessage implements Tool {
	/**
	 * @param MailMessageReading $work read work
	 */
	public function __construct(private MailMessageReading $work) {}

	/** {@inheritDoc} */
	public static function definition(): ToolDefinition {
		return MailSchema::definition('mail_read_message', 'read', MailMessages::TOOL_READ_MESSAGE, [
			'message_id' => MailSchema::id('Id of the message, from a listing, a search or a thread.'),
			'max_chars' => MailSchema::maxChars('Most characters of the body returned (default 20000, at most 100000).'),
		], ['message_id'], ['messages'], [], true);
	}

	/** {@inheritDoc} */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->work->readMessage($arguments, $context->userId);
	}
}
