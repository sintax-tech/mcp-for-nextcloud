<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Talk\Presentation;

use OCA\Mcp\Mcp\Confirmation\PlanText;

/** Existing pure value reading, quoting, reference labels and size formatting for Talk plans. */
final class TalkPlanValues {
	/** Existing excerpt bound in characters. */
	public const EXCERPT_MAX_LENGTH = 200;

	/** Static formatting only. */
	private function __construct() {
	}

	/**
	 * The conversation the plan writes into, by the name the user knows it by.
	 *
	 * @param array<string, mixed> $plan Plan of a tool that lands in an existing conversation
	 * @return string|null The display name, or null when the plan does not carry one
	 */
	public static function conversationName(array $plan): ?string {
		return self::stringAt($plan, 'conversation', 'displayName');
	}

	/**
	 * The block naming who is being answered and what was answered.
	 *
	 * @param mixed $quote replyTo block of a plan item, or null when the item quotes nothing
	 * @return string|null The block, or null when the quote is absent or incomplete
	 */
	public static function quoteBlock(mixed $quote): ?string {
		if (!is_array($quote)) {
			return null;
		}
		$author = self::stringAt($quote, 'author');
		$excerpt = self::stringAt($quote, 'excerpt');
		if ($author === null || $excerpt === null) {
			return null;
		}

		return TalkPlanMessages::planReplyingTo($author) . "\n\n" . self::excerpt($excerpt);
	}

	/**
	 * The item a message links to, named by its kind and its title, so the user sees whose item the link exposes.
	 *
	 * @param array<string, mixed> $plan Plan of talk_reply
	 * @return string|null The line, or null when the plan carries no reference or one the renderer cannot name
	 */
	public static function referenceLine(array $plan): ?string {
		$reference = $plan['reference'] ?? null;
		if (!is_array($reference)) {
			return null;
		}
		$title = self::stringAt($reference, 'title');
		$label = match ($reference['type'] ?? null) {
			'deck_card' => TalkReferenceLabels::referenceLabelDeck(),
			'calendar_event' => TalkReferenceLabels::referenceLabelCalendar(),
			default => null,
		};
		if ($title === null || $label === null) {
			return null;
		}

		return TalkPlanMessages::planLinkTo($label, $title);
	}

	/**
	 * A message body as a Markdown quote, cut when it is longer than {@see self::EXCERPT_MAX_LENGTH}.
	 *
	 * @param string $message Message as the plan carries it
	 * @return string The block quote, one "> " per line, with no line able to leave the block or to be read as Markdown
	 */
	public static function excerpt(string $message): string {
		$text = trim($message);
		$cut = mb_strlen($text) > self::EXCERPT_MAX_LENGTH;
		if ($cut) {
			$text = mb_substr($text, 0, self::EXCERPT_MAX_LENGTH);
		}

		// Every line of the message, whatever ends it ("\n", "\r\n" or a lone "\r"), stays inside the block quote.
		$quoted = PlanText::quote($text, self::EXCERPT_MAX_LENGTH);

		return $cut ? $quoted . "\n> " . TalkPlanMessages::contentTruncated() : $quoted;
	}

	/**
	 * A size a person can read, when the plan carries one.
	 *
	 * @param mixed $bytes Size in bytes, as the plan carries it
	 * @return string The size, or an empty string when the plan carries none and the line must not promise one
	 */
	public static function size(mixed $bytes): string {
		if (!is_int($bytes) || $bytes < 0) {
			return '';
		}
		if ($bytes < 1024) {
			return TalkPlanMessages::sizeInBytes($bytes);
		}
		if ($bytes < 1024 * 1024) {
			return TalkPlanMessages::sizeInKilobytes(round($bytes / 1024, 1));
		}
		if ($bytes < 1024 * 1024 * 1024) {
			return TalkPlanMessages::sizeInMegabytes(round($bytes / (1024 * 1024), 1));
		}

		return TalkPlanMessages::sizeInGigabytes(round($bytes / (1024 * 1024 * 1024), 1));
	}

	/**
	 * Reads a value nested under keys, without assuming any of them is there.
	 *
	 * @param array<string, mixed> $plan Plan, or fragment, to read from
	 * @param string ...$keys Keys to walk, in order
	 * @return mixed The value found, or null when any key of the path is missing
	 */
	public static function valueAt(array $plan, string ...$keys): mixed {
		$value = $plan;
		foreach ($keys as $key) {
			if (!is_array($value) || !array_key_exists($key, $value)) {
				return null;
			}
			$value = $value[$key];
		}

		return $value;
	}

	/**
	 * Reads a non-empty text nested under keys.
	 *
	 * @param array<string, mixed> $plan Plan, or fragment, to read from
	 * @param string ...$keys Keys to walk, in order
	 * @return string|null The text, or null when the path is missing or the value is not a usable text
	 */
	public static function stringAt(array $plan, string ...$keys): ?string {
		$value = self::valueAt($plan, ...$keys);

		return is_string($value) && trim($value) !== '' ? $value : null;
	}
}
