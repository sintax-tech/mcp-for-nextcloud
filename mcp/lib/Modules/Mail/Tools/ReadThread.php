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
use OCA\Mcp\Modules\Mail\Reading\ReadThreadHandler;

/** Publishes `mail_read_thread` and delegates its read. */
final class ReadThread implements Tool {
	/**
	 * @param MailMessageReading $work read work
	 */
	public function __construct(private MailMessageReading $work) {}

	/** {@inheritDoc} */
	public static function definition(): ToolDefinition {
		return MailSchema::definition('mail_read_thread', 'read', MailMessages::TOOL_READ_THREAD, [
			'message_id' => MailSchema::id('Id of any message of the conversation.'),
			'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ReadThreadHandler::MAX_LIMIT, 'default' => ReadThreadHandler::DEFAULT_LIMIT, 'description' => 'Most messages returned, newest first (default 50, at most 200).'],
		], ['message_id'], ['messages'], [], true);
	}

	/** {@inheritDoc} */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->work->readThread($arguments, $context->userId);
	}
}
