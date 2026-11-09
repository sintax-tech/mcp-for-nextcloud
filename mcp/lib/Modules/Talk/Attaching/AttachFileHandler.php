<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Talk\Attaching;

use InvalidArgumentException;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Modules\Talk\Attachments\FileSharer;
use OCA\Mcp\Modules\Talk\Failures\TalkErrors;
use OCA\Mcp\Modules\Talk\Locating\ConversationResolver;
use OCA\Mcp\Modules\Talk\Presentation\ToolResult;
use OCA\Mcp\Modules\Talk\TalkArguments;
use Psr\Log\LoggerInterface;

/** The existing talk_attach_file effect and result. */
final class AttachFileHandler {
	/**
	 * @param TalkArguments $arguments unchanged argument checks
	 * @param ConversationResolver $resolver writable conversation lookup
	 * @param FileSharer $sharer existing single-card room share
	 * @param LoggerInterface $logger partial-caption logger
	 * @param TalkErrors $errors unchanged refusal and logging boundary
	 */
	public function __construct(
		private TalkArguments $arguments,
		private ConversationResolver $resolver,
		private FileSharer $sharer,
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
		return $this->errors->call('talk_attach_file', function () use ($arguments, $userId): array {
			$path = $this->arguments->path($arguments);
			$caption = $this->arguments->optionalMessage($arguments);
			$conversation = $this->resolver->resolveForWriting($userId, $this->arguments->token($arguments));

			$attached = $this->sharer->attach($conversation, $userId, $path, $caption);
			if (($attached['captionSent'] ?? null) === false) {
				// The card is already in the room, so this is a real failure of the call even though it is not an
				// error result: an operator has to be able to see that the caption was lost.
				$this->logger->error('Talk caption was not sent after the attachment was published: {tool}: {exception}', [
					'app' => 'mcp',
					'tool' => 'talk_attach_file',
					'attachmentId' => $attached['attachmentId'],
					'exception' => 'CaptionFailed',
				]);
			}

			return ToolResult::success($attached);
		});
	}
}
