<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Presentation;

/** Behavioral notes that accompany the Mail catalog. */
final class MailGuideNotes {
	/** Static guide construction only. */
	private function __construct() {}

	/** @return list<string> the unchanged Mail behavior notes */
	public static function notes(): array {
		return [...MailMessages::GUIDE_NOTES, ...MailAttachmentMessages::GUIDE_NOTES, ...MailFlagMessages::GUIDE_NOTES, ...MailMoveMessages::GUIDE_NOTES, ...MailDraftMessages::GUIDE_NOTES, ...MailSendMessages::GUIDE_NOTES];
	}
}
