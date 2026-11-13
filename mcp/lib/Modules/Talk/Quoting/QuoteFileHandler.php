<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Talk\Quoting;

use InvalidArgumentException;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Modules\Talk\Failures\TalkErrors;
use OCA\Mcp\Modules\Talk\Locating\ConversationResolver;
use OCA\Mcp\Modules\Talk\Presentation\ToolResult;
use OCA\Mcp\Modules\Talk\TalkArguments;
use OCA\Mcp\Modules\Talk\Writing\ConversationWriter;

/** The existing talk_quote_file effect and result. */
final class QuoteFileHandler {
	/**
	 * @param TalkArguments $arguments unchanged argument checks
	 * @param ConversationResolver $resolver writable conversation lookup
	 * @param ConversationWriter $writer existing attachment envelope write
	 * @param TalkErrors $errors unchanged refusal and logging boundary
	 */
	public function __construct(
		private TalkArguments $arguments,
		private ConversationResolver $resolver,
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
		return $this->errors->call('talk_quote_file', function () use ($arguments, $userId): array {
			$attachmentId = $this->arguments->attachmentId($arguments);
			$caption = $this->arguments->optionalMessage($arguments);
			$conversation = $this->resolver->resolveForWriting($userId, $this->arguments->token($arguments));
			$this->resolver->openElapsedLobby($userId, $conversation);

			return ToolResult::success($this->writer->quoteAttachment($conversation, $userId, $attachmentId, $caption));
		});
	}
}
