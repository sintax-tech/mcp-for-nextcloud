<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Talk\Replying;

use InvalidArgumentException;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Modules\Talk\Failures\TalkErrors;
use OCA\Mcp\Modules\Talk\Presentation\ToolResult;
use OCA\Mcp\Modules\Talk\Writing\ConversationWriter;

/** The existing talk_reply effect and result. */
final class ReplyHandler {
	/**
	 * @param ReplyDraft $draft shared final reply resolution
	 * @param ConversationWriter $writer existing native message write
	 * @param TalkErrors $errors unchanged refusal and logging boundary
	 */
	public function __construct(
		private ReplyDraft $draft,
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
		return $this->errors->call('talk_reply', function () use ($arguments, $userId): array {
			$resolved = $this->draft->resolve($userId, $arguments);

			return ToolResult::success($this->writer->reply($resolved['conversation'], $userId, $resolved['message'], $resolved['replyTo']));
		});
	}
}
