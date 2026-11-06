<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Talk;

use InvalidArgumentException;
use OCA\Mcp\Modules\Talk\Presentation\Messages;
use OCA\Mcp\Modules\Talk\Reading\ConversationReader;
use OCA\Mcp\Modules\Talk\Writing\WritePreview;

/** The unchanged argument casts, defaults and shape checks shared by Talk work classes. */
final class TalkArguments {
	/**
	 * @param array<string, mixed> $arguments Validated arguments
	 * @return string The conversation token
	 * @throws InvalidArgumentException When the token is missing
	 */
	public function token(array $arguments): string {
		return (string)($arguments['conversation_token'] ?? throw new InvalidArgumentException(Messages::invalidToken()));
	}

	/**
	 * @param array<string, mixed> $arguments Validated arguments
	 * @return int How many messages to read
	 */
	public function limit(array $arguments): int {
		return (int)($arguments['limit'] ?? ConversationReader::DEFAULT_MESSAGES);
	}

	/**
	 * @param array<string, mixed> $arguments Validated arguments
	 * @return string The message body
	 * @throws InvalidArgumentException When the message is missing
	 */
	public function message(array $arguments): string {
		return (string)($arguments['message'] ?? throw new InvalidArgumentException(Messages::emptyMessage()));
	}

	/**
	 * @param array<string, mixed> $arguments Validated arguments
	 * @return string|null The optional caption, or null when the tool was called without one
	 */
	public function optionalMessage(array $arguments): ?string {
		return isset($arguments['message']) ? (string)$arguments['message'] : null;
	}

	/**
	 * @param array<string, mixed> $arguments Validated arguments
	 * @return string The file path
	 * @throws InvalidArgumentException When the path is missing
	 */
	public function path(array $arguments): string {
		return (string)($arguments['path'] ?? throw new InvalidArgumentException(Messages::invalidPath()));
	}

	/**
	 * @param array<string, mixed> $arguments Validated arguments
	 * @return int|null The quoted message id, when the reply quotes one
	 */
	public function replyTo(array $arguments): ?int {
		return isset($arguments['reply_to']) ? (int)$arguments['reply_to'] : null;
	}

	/**
	 * @param array<string, mixed> $arguments Validated arguments
	 * @return int The room share id of the attachment
	 * @throws InvalidArgumentException When the id is missing
	 */
	public function attachmentId(array $arguments): int {
		if (!isset($arguments['attachment_id'])) {
			throw new InvalidArgumentException(Messages::invalidIdentifier());
		}

		return (int)$arguments['attachment_id'];
	}

	/**
	 * @param array<string, mixed> $arguments Validated arguments
	 * @return array<string, mixed>|null Reference object of talk_reply, or null when absent
	 * @throws InvalidArgumentException When reference is not an object
	 */
	public function reference(array $arguments): ?array {
		$reference = $arguments['reference'] ?? null;
		if ($reference !== null && !is_array($reference)) {
			throw new InvalidArgumentException(Messages::invalidReference());
		}

		return $reference;
	}

	/**
	 * @param array<string, mixed> $arguments Validated arguments
	 * @return list<string> Accounts the call would invite, in the order received
	 * @throws InvalidArgumentException When participants is not a list of account ids
	 */
	public function participantIds(array $arguments): array {
		$participants = $arguments['participants'] ?? [];
		if (!is_array($participants) || !array_is_list($participants)) {
			throw new InvalidArgumentException(Messages::invalidUser());
		}

		$ids = [];
		foreach ($participants as $participantId) {
			if (!is_string($participantId)) {
				throw new InvalidArgumentException(Messages::invalidUser());
			}
			$ids[] = $participantId;
		}

		return $ids;
	}

	/**
	 * @param array<string, mixed> $arguments Validated arguments
	 * @return list<array{message:string, replyTo?:int|null>} Messages of the batch, in the order received
	 * @throws InvalidArgumentException When messages is not a list of items with a message
	 */
	public function batchItems(array $arguments): array {
		$messages = $arguments['messages'] ?? throw new InvalidArgumentException(Messages::emptyBatch());
		if (!is_array($messages) || !array_is_list($messages) || $messages === []) {
			throw new InvalidArgumentException(Messages::emptyBatch());
		}
		// A list longer than a user can read is a mistake in the call, and it is refused here so that neither
		// the plan nor the conversation behind it is built for something nobody could have approved.
		if (count($messages) > WritePreview::MAX_BATCH) {
			throw new InvalidArgumentException(Messages::tooManyMessages(WritePreview::MAX_BATCH));
		}

		$items = [];
		foreach ($messages as $item) {
			if (!is_array($item) || !isset($item['message']) || !is_string($item['message'])) {
				throw new InvalidArgumentException(Messages::emptyMessage());
			}
			$replyTo = $item['reply_to'] ?? null;
			$items[] = ['message' => $item['message'], 'replyTo' => $replyTo === null ? null : (int)$replyTo];
		}

		return $items;
	}

	/**
	 * @param array<string, mixed> $arguments Validated arguments
	 * @return string Account id of the message target
	 * @throws InvalidArgumentException When the account is missing
	 */
	public function targetUser(array $arguments): string {
		return (string)($arguments['user'] ?? throw new InvalidArgumentException(Messages::invalidUser()));
	}

}
