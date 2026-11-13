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
	public const TOOL = 'Save one mail attachment unchanged to a new file in Files. Shows a plan first; requires mail.save and Mail 5.10.11 or later. Replaces an existing file only with replace, after a verified copy of it unless the administrator suspended copies while native versioning is active. Never marks the message read.';
	/** @return list<string> Localized behavior notes for the save tool. */
	public static function guideNotes(): array {
		return [Translator::t('mail_save_attachment saves one unchanged attachment to a new file in Files with mail.save, a confirmed plan and confirm_shared for a foreign destination. It uses BODY.PEEK, refuses occupied names unless replace is passed, applies the final-name file type policy and reports the effective byte limit and any uncertain write. replace requires native versioning and keeps a verified copy of the current file, except while the administrator suspended copies; the plan and receipt then say that no copy was made.')];
	}

	/** Static messages only. */
	private function __construct() {
	}

	/** @return string Metadata refusal. */
	public static function metadata(): string {
		return Translator::t('The attachment metadata is missing or exceeds the save budget (128 MiB message, 512 parts, 16 levels). Nothing was saved.');
	}

	/**
	 * @param int $limit Effective decoded byte ceiling.
	 * @return string Refusal before creating any file.
	 */
	public static function limit(int $limit): string {
		return Translator::t('The attachment cannot be shown to fit the effective save limit of %s bytes. Nothing was saved.', [(string)$limit]);
	}

	/**
	 * @param int $limit Effective decoded byte ceiling.
	 * @return string Plan and receipt budget.
	 */
	public static function budget(int $limit): string {
		return Translator::t('The effective save limit for this call is %s bytes. The write is not atomic.', [(string)$limit]);
	}

	/** @return string Consequence shown before saving. */
	public static function consequence(): string {
		return Translator::t('Save one unchanged attachment to this new file. Its bytes may become visible to people with access to the destination folder. A failed write may leave a partial file; it will not be deleted automatically.');
	}

	/** @return string Honest uncertain receipt. */
	public static function uncertain(): string {
		return Translator::t('The save could not be verified. A new or partial file may remain at the destination. Check Files before trying again.');
	}

	/** @return string Read-back success. */
	public static function saved(): string {
		return Translator::t('The new file was read back and its size and SHA-256 match the attachment.');
	}

	/** @return string Occupied name without replace. */
	public static function occupied(): string {
		return Translator::t('A file with this name already exists. Nothing was written. Pass replace only after reviewing the plan: a verified backup is required unless the administrator suspended copies and native versioning is active; in that case the plan and receipt state that no copy was made.');
	}

	/** @return string Consequence shown before replacing. */
	public static function replaceConsequence(): string {
		return Translator::t('Replace the existing file with the unchanged attachment. Its bytes may become visible to people with access to the file. A failed write may leave the file partially written; it will not be restored, deleted or retried automatically.');
	}

	/** @return string Read-back success of a replacement. */
	public static function replaced(): string {
		return Translator::t('The file was replaced and read back; its size and SHA-256 match the attachment.');
	}

	/**
	 * @param string $backup Path of the verified pre-write copy, or empty when the administrator suspended copies.
	 * @return string Honest uncertain receipt of a replacement, pointing at the copy only when one exists.
	 */
	public static function replaceUncertain(string $backup): string {
		if ($backup === '') {
			return Translator::t('The replacement could not be verified. The file may hold partial content; nothing was restored or deleted. No MCP pre-write copy was made (see the backup note in this receipt): check the file and its native versions before trying again.');
		}
		return Translator::t('The replacement could not be verified. The file may hold partial content; nothing was restored or deleted. Check the file and the backup named in this receipt before trying again.');
	}

	/**
	 * @param string $size Size of the file to replace, in bytes.
	 * @param string $etag Its ETag.
	 * @return string Plan line describing the replaced file.
	 */
	public static function replaces(string $size, string $etag): string {
		return Translator::t('Replaces the existing file: %1$s bytes, ETag %2$s.', [$size, $etag]);
	}

	/**
	 * @param string $folder Backup folder that will receive the copy.
	 * @return string Plan line naming the pre-write copy.
	 */
	public static function copyFolder(string $folder): string {
		return Translator::t('A verified copy of the current file is kept in %s before the write.', [$folder]);
	}
}
