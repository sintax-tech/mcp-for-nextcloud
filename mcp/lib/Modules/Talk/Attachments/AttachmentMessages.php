<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Attachments;

use OCA\Mcp\L10n\Translator;

final class AttachmentMessages {
    /**
     * The file is missing, outside the user folder or not readable.
     * @return string The message in the language of the current user.
     */
    public static function fileNotFound(): string {
        return Translator::t('file not found or not accessible');
    }

    /**
     * The attachment id is not a share of this conversation.
     * @return string The message in the language of the current user.
     */
    public static function attachmentNotFound(): string {
        return Translator::t('attachment not found in this conversation');
    }

    /**
     * The share could not be created.
     * @return string The message in the language of the current user.
     */
    public static function fileNotShared(): string {
        return Translator::t('the file could not be shared');
    }

    /**
     * The file already carries a card in this conversation, so nothing new is published.
     * @return string The message in the language of the current user.
     */
    public static function fileAlreadyShared(): string {
        return Translator::t('the file is already shared in this conversation');
    }

    /**
     * The share was created but the caption failed, so the caption has to be sent as its own message.
     * @return string The message in the language of the current user.
     */
    public static function captionNotSent(): string {
        return Translator::t('The attachment was shared in this conversation, but the caption was not sent. Do not repeat talk_attach_file: the file is already shared and a new card would not be created. If the user still wants the caption, send it as a new message with talk_reply, following the same approval.');
    }

    /**
     * The path is missing, absolute or traverses outside the user folder.
     * @return string The message in the language of the current user.
     */
    public static function invalidPath(): string {
        return Translator::t('invalid path');
    }
}
