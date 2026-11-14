<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Talk\Messaging;

use InvalidArgumentException;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Modules\Talk\Failures\TalkErrors;
use OCA\Mcp\Modules\Talk\Locating\UserConversationResolver;
use OCA\Mcp\Modules\Talk\Presentation\ToolResult;
use OCA\Mcp\Modules\Talk\TalkArguments;
use OCA\Mcp\Modules\Talk\Writing\ConversationWriter;

/** The existing talk_message_user effect and result. */
final class MessageUserHandler {
	/**
	 * @param TalkArguments $arguments unchanged argument checks
	 * @param UserConversationResolver $userConversations existing account and native room lookup
	 * @param ConversationWriter $writer existing native message write
	 * @param TalkErrors $errors unchanged refusal and logging boundary
	 */
	public function __construct(
		private TalkArguments $arguments,
		private UserConversationResolver $userConversations,
		private ConversationWriter $writer,
		private TalkErrors $errors,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated MCP arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged MCP result envelope
	 * @throws InvalidArgumentException for invalid arguments
	 * @throws PolicyUnavailable when policy scope is unreadable
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->errors->call('talk_message_user', function () use ($arguments, $userId): array {
			$targetId = $this->arguments->targetUser($arguments);
			$message = $this->arguments->message($arguments);


			$conversation = $this->userConversations->conversation($userId, $targetId);
			$result = $this->writer->reply($conversation, $userId, $message, null);

			return ToolResult::success($result + ['user' => ['id' => $targetId, 'displayName' => $conversation->displayName()]]);
		});
	}
}
