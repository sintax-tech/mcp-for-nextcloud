<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail;

/**
 * What the message structure the server declared says about one attachment, before its content is read. Every field
 * comes from the sender: the type and the charset only choose among the readable kinds when the content agrees
 * ({@see MailAttachmentText}), and the name is shown, never used.
 */
final class MailAttachmentPart {
	/**
	 * Keeps the declared description of the part.
	 *
	 * @param string $id MIME part number, such as "2" or "2.1"
	 * @param string $type declared MIME type, lower case, such as "application/pdf"
	 * @param string|null $charset declared charset, or null
	 * @param string|null $name declared file name, or null
	 * @return void
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $type,
		public readonly ?string $charset,
		public readonly ?string $name,
	) {
	}
}
