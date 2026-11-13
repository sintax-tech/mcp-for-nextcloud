<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Status\Integration;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\L10n\Translator;

/** Stored fields copied off the native entity. Icons are never read. */
final class StatusRow {
	/**
	 * @param string $status stored status type
	 * @param int $statusTimestamp stored status timestamp
	 * @param bool $userDefined whether the type was chosen by the user
	 * @param string|null $messageId stored message id, when any
	 * @param string|null $customMessage stored custom text, when any
	 * @param int|null $clearAt stored clear-at timestamp, when any
	 * @param int $statusMessageTimestamp stored message timestamp
	 */
	public function __construct(
		public readonly string $status,
		public readonly int $statusTimestamp,
		public readonly bool $userDefined,
		public readonly ?string $messageId,
		public readonly ?string $customMessage,
		public readonly ?int $clearAt,
		public readonly int $statusMessageTimestamp,
	) {
	}

	/**
	 * @param object $entity native entity already loaded for this actor
	 * @param string $actor authenticated owner the row must belong to
	 * @return self copied fields
	 * @throws PolicyUnavailable when the entity shape is not the pinned one
	 */
	public static function from(object $entity, string $actor): self {
		$status = $entity->getStatus();
		$timestamp = $entity->getStatusTimestamp();
		$userDefined = $entity->getIsUserDefined();
		$messageId = $entity->getMessageId();
		$custom = $entity->getCustomMessage();
		$clearAt = $entity->getClearAt();
		$messageTimestamp = $entity->getStatusMessageTimestamp();
		if ($entity->getUserId() !== $actor || !is_string($status) || $status === '' || !is_int($timestamp)
			|| !is_bool($userDefined) || ($messageId !== null && !is_string($messageId))
			|| ($custom !== null && !is_string($custom)) || ($clearAt !== null && !is_int($clearAt))
			|| !is_int($messageTimestamp)) {
			throw new PolicyUnavailable(Translator::t('The status snapshot is unavailable.'));
		}
		return new self($status, $timestamp, $userDefined, $messageId, $custom, $clearAt, $messageTimestamp);
	}
}
