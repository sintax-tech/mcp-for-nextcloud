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
use OCA\Mcp\Modules\Talk\Presentation\PageMessages;
use OCA\Mcp\Modules\Talk\Presentation\ToolResult;
use OCA\Mcp\Modules\Talk\TalkArguments;

/** The talk_read_unread read and result envelope. */
final class ReadUnreadHandler {
	/**
	 * @param UnreadMessages $unread read-only page of the messages after the read marker
	 * @param TalkArguments $arguments existing token and limit casts
	 * @param TalkErrors $errors refusal and logging boundary
	 */
	public function __construct(
		private UnreadMessages $unread,
		private TalkArguments $arguments,
		private TalkErrors $errors,
	) {
	}

	/**
	 * @param array<string, mixed> $arguments validated MCP arguments
	 * @param string $userId authenticated reader
	 * @return array<string, mixed> MCP result envelope
	 * @throws InvalidArgumentException for invalid arguments
	 * @throws PolicyUnavailable when the Files scope is unreadable
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->errors->call('talk_read_unread', function () use ($arguments, $userId): array {
			$cursor = $arguments['cursor'] ?? null;
			if ($cursor !== null && !is_string($cursor)) {
				throw new InvalidArgumentException(PageMessages::invalidCursor());
			}
			return ToolResult::success($this->unread->read(
				$userId,
				$this->arguments->token($arguments),
				$this->arguments->limit($arguments),
				$cursor,
			));
		});
	}
}
