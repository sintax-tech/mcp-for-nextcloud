<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Status\Presentation;

use OCA\Mcp\L10n\Translator;

/** One status-message change: custom text, a native predefined id, or an explicit clear. */
final class StatusMessageRequest {
	/** Native StatusService::MAXIMUM_MESSAGE_LENGTH on NC 34 and 35. */
	public const MAX = 80;
	/** Custom text, the path of setCustomMessage with a null icon. */
	public const MODE_CUSTOM = 'custom';
	/** Native predefined id, the path of setPredefinedMessage. */
	public const MODE_PREDEFINED = 'predefined';
	/** Explicit removal, the path of clearMessage. An empty message is not this. */
	public const MODE_CLEAR = 'clear';
	/**
	 * Predefined ids that have a translated label on NC 34 and 35.
	 * Automation ids without a label are refused, so the plan and the receipt name the same text.
	 *
	 * @var list<string>
	 */
	public const IDS = [
		'meeting',
		'commuting',
		'sick-leave',
		'vacationing',
		'out-of-office',
		'be-right-back',
		'remote-work',
		'call',
	];

	/** @param string $mode custom, predefined, or clear */
	private function __construct(
		public readonly string $mode,
		public readonly ?string $message,
		public readonly ?string $messageId,
		public readonly ?int $clearAt,
	) {
	}

	/**
	 * @param array<string, mixed> $arguments tool arguments; another user's id is ignored
	 * @param int $now unix time the expiration is compared with
	 * @return self the one change
	 * @throws \InvalidArgumentException when the arguments are not one native change
	 */
	public static function parse(array $arguments, int $now): self {
		$hasMessage = array_key_exists('message', $arguments);
		$hasId = array_key_exists('message_id', $arguments);
		$clear = $arguments['clear'] ?? null;
		if ($clear !== null && !is_bool($clear)) {
			throw new \InvalidArgumentException(self::oneChange());
		}
		$explicit = $clear === true;
		if (($hasMessage ? 1 : 0) + ($hasId ? 1 : 0) + ($explicit ? 1 : 0) !== 1) {
			throw new \InvalidArgumentException(self::oneChange());
		}
		$clearAt = self::expiration($arguments, $now, $explicit);
		if ($explicit) {
			return new self(self::MODE_CLEAR, null, null, null);
		}
		if ($hasId) {
			$id = $arguments['message_id'];
			if (!is_string($id) || !in_array($id, self::IDS, true)) {
				throw new \InvalidArgumentException(Translator::t('The status message is not supported.'));
			}
			return new self(self::MODE_PREDEFINED, null, $id, $clearAt);
		}
		$message = $arguments['message'];
		if (!is_string($message) || $message === '') {
			throw new \InvalidArgumentException(Translator::t('The status message is empty. Removing it needs clear set to true.'));
		}
		if (!mb_check_encoding($message, 'UTF-8') || mb_strlen($message) > self::MAX) {
			throw new \InvalidArgumentException(Translator::t('The status message can have at most %d characters.', [self::MAX]));
		}
		return new self(self::MODE_CUSTOM, $message, null, $clearAt);
	}

	/** @return string|null text the plan shows after the change */
	public function after(): ?string {
		return match ($this->mode) {
			self::MODE_CLEAR => null,
			self::MODE_CUSTOM => $this->message,
			default => StatusMessages::accessible(null, $this->messageId) ?? $this->messageId,
		};
	}

	/** @return string refusal when the call is not exactly one change */
	private static function oneChange(): string {
		return Translator::t('Pass one status message change: a message, a predefined message id, or clear.');
	}

	/**
	 * @param array<string, mixed> $arguments tool arguments
	 * @param int $now unix time
	 * @param bool $explicit whether this call clears the message
	 * @return int|null future unix time, or null when the call omits it
	 * @throws \InvalidArgumentException when the value is not a future unix time, or is sent with clear
	 */
	private static function expiration(array $arguments, int $now, bool $explicit): ?int {
		if (!array_key_exists('clear_at', $arguments)) {
			return null;
		}
		if ($explicit) {
			throw new \InvalidArgumentException(Translator::t('A removed status message has no expiration.'));
		}
		$value = $arguments['clear_at'];
		if (!is_int($value) || $value < $now) {
			throw new \InvalidArgumentException(Translator::t('The status message expiration must be a unix time in the future.'));
		}
		return $value;
	}
}
