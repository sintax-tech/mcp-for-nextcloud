<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Mail\Sending;

use OCA\Mcp\Modules\Mail\Presentation\MailSendMessages;

use OCA\Mcp\Modules\Mail\Integration\MailServices;
use OCP\App\IAppManager;

/**
 * Whether the copy the Mail app saves in Sent keeps the Bcc line of a message it sends, for the note of a plan with Bcc.
 * Bcc recipients receive the message in every supported release (the SMTP envelope carries them); only the copy differs.
 *
 * Up to Mail 5.9.x, MailTransmission::sendMessage() builds the message with Horde_Mime_Mail, which keeps Bcc apart for
 * the envelope, and saves getRaw() in Sent: the copy has no Bcc line. From 5.10.11 on (the first pinned release with it)
 * it builds separate headers for the copy, Bcc included. tests/fixtures/nextcloud-api/mail-send-path.json records the two
 * forms (`_bcc`, `removeHeader`) from the SHA256-pinned sources and MailSendBccCopyContractTest ties MINIMUM_VERSION to
 * the first `removeHeader` release. Nothing is refused: the plan only says it.
 */
final class MailSendBccCopy {
	/** First pinned Mail release whose copy in Sent keeps Bcc. */
	public const MINIMUM_VERSION = '5.10.11';

	/** The copy keeps Bcc, or there is no Bcc: no note. */
	public const KEPT = 'kept';

	/** The installed Mail is older than MINIMUM_VERSION: the copy will not keep Bcc. */
	public const LOST = 'lost';

	/** The installed Mail version cannot be read: the copy may not keep Bcc. */
	public const UNKNOWN = 'unknown';

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * What the copy in Sent does with the Bcc line of this message.
	 *
	 * @param MailSendRequest $message the message asked for
	 * @param IAppManager $apps app manager, for the installed Mail version
	 * @return string KEPT, LOST or UNKNOWN; KEPT for a message without Bcc
	 */
	public static function of(MailSendRequest $message, IAppManager $apps): string {
		if ($message->recipients['bcc'] === []) {
			return self::KEPT;
		}
		$version = (string)$apps->getAppVersion(MailServices::APP_ID);
		if (preg_match('/^\d+\.\d+\.\d+/', $version) !== 1) {
			return self::UNKNOWN;
		}
		return version_compare($version, self::MINIMUM_VERSION, '>=') ? self::KEPT : self::LOST;
	}

	/**
	 * The note of a plan for a copy that does not, or may not, keep Bcc.
	 *
	 * @param string $copy KEPT, LOST or UNKNOWN
	 * @return string|null the note, or null when the copy keeps Bcc
	 */
	public static function note(string $copy): ?string {
		return match ($copy) {
			self::LOST => MailSendMessages::bccCopyLost(self::MINIMUM_VERSION),
			self::UNKNOWN => MailSendMessages::bccCopyMaybeLost(),
			default => null,
		};
	}
}
