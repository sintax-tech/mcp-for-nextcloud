<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Talk\Batching;

use InvalidArgumentException;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Talk\Failures\TalkErrors;
use OCA\Mcp\Modules\Talk\Locating\ConversationResolver;
use OCA\Mcp\Modules\Talk\TalkArguments;
use OCA\Mcp\Modules\Talk\Writing\WritePreview;

/** The existing talk_send_batch preview without publication. */
final class SendBatchPreview {
	/**
	 * @param TalkArguments $arguments unchanged batch shape checks
	 * @param ConversationResolver $resolver writable conversation lookup
	 * @param WritePreview $preview existing complete batch draft
	 * @param TalkErrors $errors unchanged refusal and logging boundary
	 */
	public function __construct(
		private TalkArguments $arguments,
		private ConversationResolver $resolver,
		private WritePreview $preview,
		private TalkErrors $errors,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments validated MCP arguments
	 * @param string $userId authenticated caller
	 * @return array<string,mixed> unchanged confirmation plan
	 * @throws InvalidArgumentException for invalid arguments
	 * @throws PolicyUnavailable when policy scope is unreadable
	 * @throws ToolFailure when the same call would be refused
	 */
	public function plan(array $arguments, string $userId): array {
		return $this->errors->preview('talk_send_batch', function () use ($arguments, $userId): array {
			return $this->preview->batch(
				$this->resolver->resolveForWriting($userId, $this->arguments->token($arguments)),
				$userId,
				$this->arguments->batchItems($arguments),
			);
		});
	}
}
