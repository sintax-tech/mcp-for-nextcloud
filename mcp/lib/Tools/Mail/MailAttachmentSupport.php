<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail;

use OCP\App\IAppManager;

/**
 * Whether the installed Mail app reads an attachment without marking its message as read.
 *
 * The attachment is read by the Mail app's own `OCA\Mail\IMAP\MessageMapper::getAttachment()`. Up to Mail 5.10.10
 * (and in every release line before 5.10) that method fetches the part with BODY[part], which sets the \Seen flag on
 * the server; from 5.10.11 on, and in every 5.11 and later release, it fetches with BODY.PEEK[part]. Marking the
 * message unread again afterwards would be a write, and fetching the part outside the Mail app a parallel IMAP stack,
 * so the attachment tool exists only from that release on. Fail-closed: a version the server cannot tell counts as
 * too old, as for the Deck dependency tools ({@see \OCA\Mcp\Tools\Deck\DeckCapabilities}).
 */
final class MailAttachmentSupport {
	/** First Mail release whose MessageMapper::getAttachment() reads the part and its MIME header with PEEK. */
	public const MINIMUM_VERSION = '5.10.11';

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * Whether the installed Mail app is recent enough for the attachment tool.
	 *
	 * @param IAppManager $apps the server's app manager
	 * @return bool true from Mail 5.10.11 on; false for an older or unknown version
	 */
	public static function available(IAppManager $apps): bool {
		$version = (string)$apps->getAppVersion(MailServices::APP_ID);
		return $version !== '' && version_compare($version, self::MINIMUM_VERSION, '>=');
	}
}
