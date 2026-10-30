<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail\Draft;

use OCA\Mcp\Tools\Mail\MailServices;
use OCA\Mcp\Tools\ToolFailure;
use OCP\App\IAppManager;

/**
 * Whether the installed Mail keeps the Bcc recipients of a draft it saves, so a plan with Bcc is never confirmed into a
 * draft without them.
 *
 * Up to Mail 5.9.x, MailTransmission::saveDraft() hands the headers to Horde_Mime_Mail::addHeaders() and stores
 * getRaw(): Horde_Mime_Mail keeps a Bcc header apart (`_bcc`, for the transport) and getRaw() leaves it out, so the saved
 * draft has no Bcc. From 5.10.11 on (the first pinned release with it; 5.11.0 and 5.12.5 the same) it builds the headers
 * with buildMimeHeaders(), Bcc included, and stores the message part with them. tests/fixtures/nextcloud-api/
 * mail-draft-bcc.json records that from the SHA256-pinned sources and MailDraftBccContractTest ties MINIMUM_VERSION to
 * it. A draft with Bcc is refused, before the plan and again before the save, below that release or when the version
 * cannot be read; a draft without Bcc is not affected.
 */
final class MailDraftBcc {
	/** First pinned Mail release whose draft save keeps Bcc. */
	public const MINIMUM_VERSION = '5.10.11';

	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * Refuses a draft with Bcc that the installed Mail would save without it.
	 *
	 * @param MailDraftRequest $draft the draft asked for
	 * @param IAppManager $apps app manager, for the installed Mail version
	 * @return void
	 * @throws ToolFailure when the draft has Bcc and the Mail version is older than MINIMUM_VERSION or unknown
	 */
	public static function assertKept(MailDraftRequest $draft, IAppManager $apps): void {
		if ($draft->recipients['bcc'] !== [] && !self::kept((string)$apps->getAppVersion(MailServices::APP_ID))) {
			throw new ToolFailure(MailDraftMessages::bccNotKept(self::MINIMUM_VERSION));
		}
	}

	/**
	 * Whether a Mail version keeps Bcc in saved drafts.
	 *
	 * @param string $version installed Mail version, as the app manager reports it
	 * @return bool true from MINIMUM_VERSION on; false for an older, empty or unreadable version
	 */
	public static function kept(string $version): bool {
		return preg_match('/^\d+\.\d+\.\d+/', $version) === 1 && version_compare($version, self::MINIMUM_VERSION, '>=');
	}
}
