<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Creation;

use OCA\Mcp\Modules\Mail\Presentation\MailDraftMessages;

use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;

/**
 * The draft a call asks for, what the request of the issue names ("create a draft in the user's Drafts folder"): the
 * recipients (To, Cc, Bcc), the subject and a plain text. No attachment, reply, HTML or sender other than the account.
 *
 * Recipients are plain addresses only, so the list the Mail app parses (OCA\Mail\AddressList::parse()) is exactly the one
 * the plan shows: no display name, comma or group syntax can turn one entry into several or none. The subject is one line;
 * the text keeps its line breaks.
 */
final class MailDraftRequest {
	/** Most recipients per field. */
	public const MAX_RECIPIENTS = 50;

	/** Longest subject, in characters. */
	public const MAX_SUBJECT = 255;

	/** Longest text, in characters. */
	public const MAX_BODY = 50000;

	/** The recipient fields, in the order of the message headers. */
	public const FIELDS = ['to', 'cc', 'bcc'];

	/**
	 * Keeps the asked draft.
	 *
	 * @param array{to:list<string>, cc:list<string>, bcc:list<string>} $recipients plain addresses by field
	 * @param string $subject one line
	 * @param string $body plain text or sanitized HTML
	 * @param bool $html whether the native draft is HTML
	 * @param array<string,mixed> $htmlDetails HTML preview and removal report
	 * @return void
	 */
	private function __construct(
		public readonly array $recipients,
		public readonly string $subject,
		public readonly string $body,
		public readonly bool $html = false,
		public readonly array $htmlDetails = [],
	) {
	}

	/**
	 * The request of a call.
	 *
	 * @param array<string, mixed> $arguments arguments validated against the schema
	 * @return self the request
	 * @throws ArgumentValidationException for a recipient that is not one plain address, a subject with a control
	 *     character or a text with NUL
	 */
	public static function from(array $arguments): self {
		$recipients = [];
		foreach (self::FIELDS as $field) {
			$recipients[$field] = [];
			foreach ((array)($arguments[$field] ?? []) as $address) {
				$address = trim((string)$address);
				if (!self::plainAddress($address)) {
					throw new ArgumentValidationException('Invalid argument: ' . $field, $field, MailDraftMessages::invalidAddress($field));
				}
				$recipients[$field][] = $address;
			}
		}
		$subject = (string)($arguments['subject'] ?? '');
		if (preg_match('/\p{Cc}/u', $subject) === 1 || !mb_check_encoding($subject, 'UTF-8')) {
			throw new ArgumentValidationException('Invalid argument: subject', 'subject', MailDraftMessages::invalidSubject());
		}
		$body = (string)($arguments['body'] ?? '');
		if (str_contains($body, "\0") || !mb_check_encoding($body, 'UTF-8')) {
			throw new ArgumentValidationException('Invalid argument: body', 'body', MailDraftMessages::invalidBody());
		}
		if (array_key_exists('html', $arguments) && !is_bool($arguments['html'])) {
			throw new ArgumentValidationException('Invalid argument: html', 'html');
		}
		if (mb_strlen($body) > self::MAX_BODY) {
			throw new ArgumentValidationException('Invalid argument: body', 'body', MailDraftMessages::htmlTooLong());
		}
		return new self($recipients, $subject, $body, $arguments['html'] ?? false);
	}

	/**
	 * Prepares HTML only after account resolution checked actor enablement, on preview and confirmation alike.
	 *
	 * @param MailDraftHtml $sanitizer restricted HTML pipeline
	 * @return self draft whose saved body and confirmation data come from the same fragment
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure when HTML cannot be sanitized
	 */
	public function sanitized(MailDraftHtml $sanitizer): self {
		if (!$this->html) {
			return $this;
		}
		$result = $sanitizer->sanitize($this->body);
		return new self($this->recipients, $this->subject, $result['body'], true,
			\OCA\Mcp\Modules\Mail\Presentation\MailDraftHtmlView::fields($result));
	}

	/**
	 * The recipients of a field as one list the Mail app parses back into the same addresses.
	 *
	 * @param string $field to, cc or bcc
	 * @return string the addresses joined with commas, empty for none
	 */
	public function joined(string $field): string {
		return implode(', ', $this->recipients[$field]);
	}

	/**
	 * Whether a value is one plain e-mail address: no display name, quotes, comments, group or second address.
	 *
	 * @param string $address the value, trimmed
	 * @return bool true for a plain address
	 */
	private static function plainAddress(string $address): bool {
		return $address !== '' && strlen($address) <= 254 && preg_match('/[\s,;:<>()\[\]"\\\\]/', $address) !== 1
			&& filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
	}
}
