<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Talk\Batching;

use InvalidArgumentException;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Modules\Talk\Failures\TalkErrors;
use OCA\Mcp\Modules\Talk\Locating\ConversationResolver;
use OCA\Mcp\Modules\Talk\Presentation\ToolResult;
use OCA\Mcp\Modules\Talk\TalkArguments;
use OCA\Mcp\Modules\Talk\Writing\ConversationWriter;
use Psr\Log\LoggerInterface;

/** The existing talk_send_batch effect and result. */
final class SendBatchHandler {
	/**
	 * @param TalkArguments $arguments unchanged batch shape checks
	 * @param ConversationResolver $resolver writable conversation lookup
	 * @param ConversationWriter $writer existing per-item message write
	 * @param LoggerInterface $logger partial-batch logger
	 * @param TalkErrors $errors unchanged refusal and logging boundary
	 */
	public function __construct(
		private TalkArguments $arguments,
		private ConversationResolver $resolver,
		private ConversationWriter $writer,
		private LoggerInterface $logger,
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
		return $this->errors->call('talk_send_batch', function () use ($arguments, $userId): array {
			// The shape of the list is a client mistake, so it is checked before any conversation is looked up.
			$items = $this->arguments->batchItems($arguments);
			$conversation = $this->resolver->resolveForWriting($userId, $this->arguments->token($arguments));


			$result = $this->writer->replyMany($conversation, $userId, $items);

			if ($result['failed'] !== []) {
				// Part of the batch is in the room now, so the result has to tell which: a generic failure would invite
				// a retry that republishes what already went out.
				$this->logger->warning('Talk sent only part of the batch in conversation {token}', [
					'token' => $conversation->token(),
					'sent' => count($result['sent']),
					'failed' => count($result['failed']),
				]);
			}

			return ToolResult::success($result);
		});
	}
}
