<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Presentation;

use OCA\Mcp\L10n\Translator;

/** Wording of the binary save path; MIME remains descriptive and bytes remain unmodified. */
final class MailSaveMessages {
	public const TOOL = 'Save one mail attachment unchanged to a new file in Files. Shows a plan first; requires mail.save and Mail 5.10.11 or later. Never replaces an existing file or marks the message read.';
	/** Static messages only. */
	private function __construct() {}
	/** @return string Metadata refusal. */
	public static function metadata(): string { return Translator::t('The attachment metadata is missing or exceeds the save budget (128 MiB message, 512 parts, 16 levels). Nothing was saved.'); }
	/** @param int $limit Effective decoded byte ceiling. @return string Refusal before creating any file. */
	public static function limit(int $limit): string { return Translator::t('The attachment cannot be shown to fit the effective save limit of %s bytes. Nothing was saved.', [(string)$limit]); }
	/** @param int $limit Effective decoded byte ceiling. @return string Plan and receipt budget. */
	public static function budget(int $limit): string { return Translator::t('The effective save limit for this call is %s bytes. The write is not atomic.', [(string)$limit]); }
	/** @return string Consequence shown before saving. */
	public static function consequence(): string { return Translator::t('Save one unchanged attachment to this new file. Its bytes may become visible to people with access to the destination folder. A failed write may leave a partial file; it will not be deleted automatically.'); }
	/** @return string Honest uncertain receipt. */
	public static function uncertain(): string { return Translator::t('The save could not be verified. A new or partial file may remain at the destination. Check Files before trying again.'); }
	/** @return string Read-back success. */
	public static function saved(): string { return Translator::t('The new file was read back and its size and SHA-256 match the attachment.'); }
}
