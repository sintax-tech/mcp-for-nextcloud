<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Checkout;

use OCA\Mcp\L10n\Translator;

final class CheckoutMessages {
    /** @return string policy could not be resolved; the link remains unspent */
    public static function checkoutUnavailable(): string {
        return Translator::t('MCP access cannot be checked right now. Try again shortly.');
    }

    /** @return string the checkout token is of the wrong kind for this route */
    public static function tokenWrongKind(): string {
        return Translator::t('Invalid edit link.');
    }

    /** @return string the checkout token is unknown */
    public static function tokenInvalid(): string {
        return Translator::t('Invalid edit link.');
    }

    /** @return string app service, eligibility or connection is off right now */
    public static function checkoutRevoked(): string {
        return Translator::t('MCP connection is disabled or access to this folder is no longer granted.');
    }

    /** @return string the create link of files_upload expired or was already used */
    public static function createTokenSpent(): string {
        return Translator::t('This upload link has expired or has already been used. Call files_upload again.');
    }

    /** @return string the checkout token expired or was already used */
    public static function tokenSpent(): string {
        return Translator::t('This edit link has expired or has already been used. Please checkout again.');
    }

    /**
     * @return string what the links of a checkout mean
     */
    public static function planCheckoutConsequence(): string {
        return Translator::t('The upload replaces the whole file, takes the same backup as an edit and is refused if the file changed since the checkout.');
    }

    /**
     * @return string the plan of files_checkout
     */
    public static function planCheckout(): string {
        return Translator::t('Nothing was changed. After your approval a single-use download link and a single-use upload link are issued for this file, which can be any file type (document, spreadsheet, PDF, image) up to the upload limit below; the upload only succeeds while the ETag below is the current one. A text file can also be edited directly with files_edit or files_replace.');
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

    /**
     * @param string $backup path of the verified copy of the original, '' when the failure came before it
     * @return string a failed write, naming where the original still is
     */
    public static function uploadFailed(string $backup): string {
        return $backup === ''
            ? Translator::t('Failed to write file.')
            : Translator::t('Failed to write file; original preserved in %s.', [$backup]);
    }

    /**
     * @param int $declared size files_upload declared for the file
     * @return string an empty body for a link that expected bytes: the client failed to build the file
     */
    public static function uploadEmptyDeclared(int $declared): string {
        return Translator::t('The uploaded body is empty, but files_upload declared %s bytes; nothing was written and the link still works.', [(string)$declared]);
    }

    /** @return string description of files_checkout */
    public static function checkoutTool(): string {
        return 'Provide temporary download and upload links to edit any file type (document, spreadsheet, PDF, '
            . 'image) with local tools (curl) without passing content through the model, up to the upload limit. '
            . 'Upload raw bytes with curl -T /tmp/file -X PUT -H "Content-Type: application/octet-stream" "$UPLOAD_URL". '
            . 'A text file can also be edited directly with files_edit or files_replace. Links are valid for 5 to 15 minutes, single-use, '
            . 'and bound to you, the file, and current ETag: if the file changes before upload, nothing is '
            . 'written. Upload creates the same backup as files_edit. If the file is outside your personal '
            . 'folder, only issue links after asking the user and retrying with confirm_shared.';
    }

    /** @return string a multipart body, the shape `curl -F` produces instead of raw bytes */
    public static function uploadMultipart(): string {
        return Translator::t('Send file bytes as raw body (curl -T), not as multipart form.');
    }

    /** @return string a body that carried nothing at all */
    public static function uploadEmpty(): string {
        return Translator::t('The uploaded body is empty; nothing was written.');
    }
}
