<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

/**
 * The exact description of a mail card. Everything taken from the email (subject, sender, excerpt) was written by
 * whoever sent it: it is copied as data, its Markdown escaped and the excerpt fenced as code, so nothing in it renders
 * as a link or reads as an instruction. The reference is private: the Mail app link and the private link of the file
 * open only for who can already read them; there is no IMAP address and no public download link.
 */
final class MailCardText {
	/** Static text only. */
	private function __construct() {
	}

	/**
	 * @param array{subject: string, from: array<string, mixed>, date: string, url: string|null, body: array<string, mixed>} $message the message, as the mail card source read it
	 * @param string $note the user's own text, '' for none
	 * @param string $path the path of the saved file in the actor's Files
	 * @param string|null $fileLink the private link of the saved file; null in the plan, before the file exists
	 * @param bool $excerpt whether the card copies the excerpt of the body
	 * @return string the description of the card
	 */
	public static function description(array $message, string $note, string $path, ?string $fileLink, bool $excerpt): string {
		$lines = $note === '' ? [] : [$note, ''];
		$lines[] = Translator::t('Email: %s', [PlanText::inline((string)$message['subject'])]);
		$lines[] = Translator::t('From: %s', [self::sender($message['from'])]);
		$lines[] = Translator::t('Received: %s', [PlanText::inline((string)$message['date'])]);
		if (is_string($message['url'] ?? null)) {
			$lines[] = Translator::t('Open in Mail: %s', [$message['url']]);
		}
		$lines[] = Translator::t('Saved file: %s', [PlanText::inline($path, 1024)]);
		if ($fileLink !== null) {
			$lines[] = Translator::t('File link: %s', [$fileLink]);
		}
		$lines[] = MailCardMessages::privateFile();
		$text = (string)($message['body']['text'] ?? '');
		if ($excerpt && $text !== '') {
			$fence = str_repeat('`', max(3, self::longestRun($text) + 1));
			array_push($lines, '', Translator::t('Excerpt of the email, copied as plain text:'), $fence, $text, $fence);
		}
		return implode("\n", $lines);
	}

	/**
	 * @param array<string, mixed> $from the sender field of the message
	 * @return string the first sender, name and address, escaped; '' when the message names none
	 */
	private static function sender(array $from): string {
		$first = $from['addresses'][0] ?? null;
		if (!is_array($first)) {
			return '';
		}
		$name = trim((string)($first['name'] ?? ''));
		$email = (string)($first['email'] ?? '');
		return PlanText::inline($name === '' ? $email : $name . ' <' . $email . '>');
	}

	/**
	 * @param string $text the excerpt
	 * @return int the longest run of backticks in it, so the fence around it is always longer
	 */
	private static function longestRun(string $text): int {
		preg_match_all('/`+/', $text, $runs);
		return max([0, ...array_map('strlen', $runs[0])]);
	}
}
