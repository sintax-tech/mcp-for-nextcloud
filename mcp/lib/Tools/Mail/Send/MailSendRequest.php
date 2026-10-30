<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail\Send;

use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Mail\Draft\MailDraftRequest;

/**
 * The message a call asks to send: the same fields and rules as a draft ({@see MailDraftRequest}: plain recipients by
 * field, a one-line subject, a plain text), read through it unchanged, plus what sending needs: at least one recipient.
 * No attachment, reply, forward, HTML or sender other than the account.
 */
final class MailSendRequest {
	/**
	 * Keeps the message asked for.
	 *
	 * @param array{to:list<string>, cc:list<string>, bcc:list<string>} $recipients plain addresses by field
	 * @param string $subject one line
	 * @param string $body plain text
	 * @return void
	 */
	private function __construct(
		public readonly array $recipients,
		public readonly string $subject,
		public readonly string $body,
	) {
	}

	/**
	 * The request of a call.
	 *
	 * @param array<string, mixed> $arguments arguments validated against the schema
	 * @return self the request
	 * @throws ArgumentValidationException for a recipient that is not one plain address, a subject with a control
	 *     character, a text with NUL, or no recipient at all
	 */
	public static function from(array $arguments): self {
		$draft = MailDraftRequest::from($arguments);
		if ($draft->recipients['to'] === [] && $draft->recipients['cc'] === [] && $draft->recipients['bcc'] === []) {
			throw new ArgumentValidationException('Invalid argument: to', 'to', MailSendMessages::noRecipient());
		}
		return new self($draft->recipients, $draft->subject, $draft->body);
	}

	/**
	 * The recipients of a field as the Mail app's Outbox takes them: one entry per address, labelled with the address.
	 *
	 * @param string $field to, cc or bcc
	 * @return list<array{email:string, label:string}> the entries
	 */
	public function outboxRecipients(string $field): array {
		return array_map(static fn (string $address): array => ['email' => $address, 'label' => $address], $this->recipients[$field]);
	}
}
