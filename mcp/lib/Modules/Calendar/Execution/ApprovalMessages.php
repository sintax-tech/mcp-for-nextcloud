<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Execution;

use OCA\Mcp\L10n\Translator;
final class ApprovalMessages
{
    public static function approvalPrompt(): string
    {
        return Translator::t('Show this draft to the user and await explicit approval. Only then repeat the same arguments with confirm: true (and the etag of this plan, if present).');
    }
    public static function approvalShared(): string
    {
        return Translator::t('The plan changes a shared calendar belonging to someone else. Execution also requires confirm_shared: true.');
    }
    public static function previewCancel(): string
    {
        return Translator::t('After approval, the event will go to the trash bin and Nextcloud may schedule CANCEL to attendees. Sending and delivery are not guaranteed.');
    }
    public static function previewInvitationProof(): string
    {
        return Translator::t('Invitations are handed to the CalDAV scheduling of this server; receipt of external email is not proved.');
    }
    public static function previewInvitations(): string
    {
        return Translator::t('After approval, Nextcloud may schedule invitations, updates or CANCEL for removed attendees. Sending and delivery are not guaranteed.');
    }
    public static function previewSuppressed(): string
    {
        return Translator::t('No invitations or cancellations will be scheduled in this change.');
    }
    public static function previewTrash(): string
    {
        return Translator::t('After approval, the event will go to the recoverable Calendar trash bin.');
    }
}
