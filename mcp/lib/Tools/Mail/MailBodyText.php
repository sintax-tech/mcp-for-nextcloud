<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail;

/**
 * The body of a message as plain text the model can read, bounded.
 *
 * The plain-text part is used when the message has one. Otherwise the HTML part, already sanitized by the Mail app,
 * is turned into text here: style, script and head contents are dropped, block elements become line breaks, every tag
 * is removed and entities are decoded. Nothing is rendered and no remote resource is ever fetched. Control and
 * bidirectional formatting characters are removed, as in every Mail answer, but line breaks are kept.
 */
final class MailBodyText {
	/** Longest body returned, in characters: the limit of files_read ({@see \OCA\Mcp\Tools\Files\TextExtractor::MAX_CHARS}). */
	public const MAX_CHARS = 100_000;

	/**
	 * The body text and whether it was cut.
	 *
	 * @param string $plain text/plain body of the message, possibly empty
	 * @param string|null $html sanitized HTML body, or null when there is none or the plain body is used
	 * @param int $maxChars most characters returned, at most MAX_CHARS
	 * @return array{text:string, format:string, truncated:bool, totalChars:int} the text, plain or html, the cut and
	 *     the length before the cut
	 */
	public static function from(string $plain, ?string $html, int $maxChars = self::MAX_CHARS): array {
		$format = 'plain';
		$text = $plain;
		if (trim($plain) === '' && $html !== null && trim($html) !== '') {
			$format = 'html';
			$text = self::htmlToText($html);
		}
		$text = self::clean($text);
		$total = mb_strlen($text);
		$limit = max(1, min($maxChars, self::MAX_CHARS));
		return [
			'text' => $total > $limit ? mb_substr($text, 0, $limit) : $text,
			'format' => $format,
			'truncated' => $total > $limit,
			'totalChars' => $total,
		];
	}

	/**
	 * HTML turned into text, without rendering it.
	 *
	 * @param string $html sanitized HTML
	 * @return string the visible text
	 */
	public static function htmlToText(string $html): string {
		$text = self::replace('~<(script|style|head|title)\b[^>]*>.*?</\1\s*>~is', ' ', $html);
		$text = self::replace('~<!--.*?-->~s', ' ', $text);
		$text = self::replace('~<(br|hr)\b[^>]*>~i', "\n", $text);
		$text = self::replace('~</?(p|div|li|ul|ol|tr|table|h[1-6]|blockquote|pre|section|article|header|footer)\b[^>]*>~i', "\n", $text);
		$text = self::replace('~</t[dh]\s*>~i', "\t", $text);
		$text = strip_tags($text);
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = self::replace('/[ \t\x{00A0}]+/u', ' ', $text);
		return trim(self::replace("/ *\n[ \n]*/", "\n\n", $text));
	}

	/**
	 * preg_replace() that keeps the subject when PCRE gives up (backtrack or recursion limit, invalid UTF-8), so a
	 * hostile body can never turn into an empty one; strip_tags() still removes every tag afterwards.
	 *
	 * @param string $pattern regular expression
	 * @param string $replacement replacement
	 * @param string $subject text
	 * @return string the replaced text, or the subject unchanged
	 */
	private static function replace(string $pattern, string $replacement, string $subject): string {
		$result = preg_replace($pattern, $replacement, $subject);
		return $result ?? $subject;
	}

	/**
	 * Untrusted text made safe to show, keeping its line breaks and tabs.
	 *
	 * @param string $text raw text
	 * @return string the cleaned text
	 */
	private static function clean(string $text): string {
		$text = str_replace(["\r\n", "\r"], "\n", mb_scrub($text, 'UTF-8'));
		return (string)preg_replace('/[^\P{Cc}\n\t]|[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $text);
	}
}
