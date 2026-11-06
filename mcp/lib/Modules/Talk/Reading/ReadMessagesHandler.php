<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Talk\Reading;

use InvalidArgumentException;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Modules\Talk\Failures\TalkErrors;
use OCA\Mcp\Modules\Talk\Presentation\ToolResult;
use OCA\Mcp\Modules\Talk\TalkArguments;

/** The unchanged talk_read_messages read and result envelope. */
final class ReadMessagesHandler {
	/**
	 * @param ConversationReader $reader existing filtered message history
	 * @param TalkArguments $arguments unchanged token and limit casts
	 * @param TalkErrors $errors unchanged refusal and logging boundary
	 */
	public function __construct(
		private ConversationReader $reader,
		private TalkArguments $arguments,
		private TalkErrors $errors,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated MCP arguments
	 * @param string $userId authenticated reader
	 * @return array<string,mixed> unchanged MCP result envelope
	 * @throws InvalidArgumentException for invalid arguments
	 * @throws PolicyUnavailable when Files scope is unreadable
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->errors->call('talk_read_messages', fn (): array => ToolResult::success(['messages' => $this->reader->readMessages($userId, $this->arguments->token($arguments), $this->arguments->limit($arguments))]));
	}
}
