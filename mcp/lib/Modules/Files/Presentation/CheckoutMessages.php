<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Presentation;

use OCA\Mcp\L10n\Translator;

/** Tool descriptions, parameter descriptions and user messages for Files checkout. */
final class CheckoutMessages {
	/** @return string description of files_checkout */
	public static function checkoutTool(): string {
		return \OCA\Mcp\Modules\Files\Backups\BackupMessages::checkoutTool();
	}

	/**
	 * @return string the plan of files_checkout
	 */
	public static function planCheckout(): string {
		return Translator::t('Nothing was changed. After your approval a single-use download link and a single-use upload link are issued for this file, which can be any file type (document, spreadsheet, PDF, image) up to the upload limit below; the upload only succeeds while the ETag below is the current one. A text file can also be edited directly with files_edit or files_replace.');
	}

	/**
	 * @return string what the links of a checkout mean
	 */
	public static function planCheckoutConsequence(): string {
		return \OCA\Mcp\Modules\Files\Backups\BackupMessages::planCheckoutConsequence();
	}

	/**
	 * @param string $path normalized path refused for checkout
	 * @return string a folder or a non-text file
	 */
	public static function checkoutNotEditable(string $path): string {
		return Translator::t('Cannot prepare editing for this file.');
	}

	/** @return string app service, eligibility or connection is off right now */
	public static function checkoutRevoked(): string {
		return Translator::t('MCP connection is disabled or access to this folder is no longer granted.');
	}

	/** @return string the access policy cannot be resolved now: nothing was decided, the link still works later */
	public static function checkoutUnavailable(): string {
		return Translator::t('MCP access cannot be checked right now. Try the link again shortly.');
	}

	/** @return string the checkout token is unknown */
	public static function tokenInvalid(): string {
		return Translator::t('Invalid edit link.');
	}

	/** @return string the checkout token expired or was already used */
	public static function tokenSpent(): string {
		return Translator::t('This edit link has expired or has already been used. Please checkout again.');
	}

	/** @return string the checkout token is of the wrong kind for this route */
	public static function tokenWrongKind(): string {
		return Translator::t('Invalid edit link.');
	}

	/**
	 * @param int $limit effective limit in bytes
	 * @return string an upload over the limit
	 */
	public static function uploadTooLarge(int $limit): string {
		return Translator::t('Content exceeds the upload limit of %s bytes.', [(string)$limit]);
	}

	/**
	 * The ETag no longer matches. The token is already spent, so the agent cannot retry it: it has to read
	 * the file again and make a new checkout, which is what this message asks for.
	 *
	 * @return string the client-safe reason for a 409 on the upload
	 */
	public static function uploadConflict(): string {
		return Translator::t('The file changed after checkout; nothing was written. Re-read the file and run a new files_checkout before uploading again.');
	}

	/** @return string a multipart body, the shape `curl -F` produces instead of raw bytes */
	public static function uploadMultipart(): string {
		return Translator::t('Send file bytes as raw body (curl -T), not as multipart form.');
	}

	/** @return string a body the upload route does not interpret */
	public static function uploadWrongType(): string {
		return Translator::t('Send body as application/octet-stream with file bytes.');
	}

	/** @return string a body that carried nothing at all */
	public static function uploadEmpty(): string {
		return Translator::t('The uploaded body is empty; nothing was written.');
	}

	/**
	 * @param string $backup path of the verified copy of the original, '' when the failure came before it
	 * @return string a failed write, naming where the original still is
	 */
	public static function uploadFailed(string $backup): string {
		return $backup === ''
			? Translator::t('Failed to write file.')
			: Translator::t('Failed to write file; original preserved in %s.', [$backup]);
	}
}
