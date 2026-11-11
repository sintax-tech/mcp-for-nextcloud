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
use OCA\Mcp\Modules\Talk\Presentation\Messages;
use OCA\Mcp\Modules\Talk\Presentation\ToolResult;

/** The talk_list_unread read and result envelope. */
final class ListUnreadHandler {
	/**
	 * @param UnreadConversations $unread read-only page of conversations with unread messages
	 * @param TalkErrors $errors refusal and logging boundary
	 */
	public function __construct(
		private UnreadConversations $unread,
		private TalkErrors $errors,
	) {
	}

	/**
	 * @param array<string, mixed> $arguments validated MCP arguments
	 * @param string $userId authenticated reader
	 * @return array<string, mixed> MCP result envelope
	 * @throws InvalidArgumentException for invalid arguments
	 * @throws PolicyUnavailable when a scope read inside the call cannot be resolved
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->errors->call('talk_list_unread', function () use ($arguments, $userId): array {
			$limit = array_key_exists('limit', $arguments) ? $arguments['limit'] : UnreadConversations::DEFAULT_PAGE;
			if (!is_int($limit)) {
				throw new InvalidArgumentException(Messages::invalidPageLimit());
			}
			$cursor = $arguments['cursor'] ?? null;
			if ($cursor !== null && !is_string($cursor)) {
				throw new InvalidArgumentException(Messages::invalidCursor());
			}
			return ToolResult::success($this->unread->list($userId, $limit, $cursor));
		});
	}
}
