<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Sharing;

use OCA\Mcp\L10n\Translator;

final class SharingMessages {
    /** @return string the password policy refused every password the server generated for a link */
    public static function linkPasswordRefused(): string {
        return Translator::t('The server could not generate a password that meets the password policy, so no link was created. Ask your administrator to check the password policy.');
    }

    /** @return string the warning of a plan a confirmed files_share or files_unshare answers with, because the share changed */
    public static function sharePlanChanged(): string {
        return Translator::t('The share changed since the plan; check it again.');
    }

    /** @return string advice for the model when the share already is what was asked */
    public static function planShareNothing(): string {
        return Translator::t('Nothing was changed. The share already is exactly like this, so a confirmed call changes nothing either.');
    }

    /** @return string any other refusal of the core, without its message */
    public static function shareRefused(): string {
        return Translator::t('Nextcloud refused this share because of a sharing rule of this server.');
    }

    /** @return string what the result of a link says next to the password it generated */
    public static function linkPasswordShownOnce(): string {
        return Translator::t('Save this password now and send it separately from the link: it will not be shown again. To change it later, share the link again with password: true.');
    }

    /** @return string a share whose recipient is the user */
    public static function shareWithSelf(): string {
        return Translator::t('You already have this item; choose another person to share it with.');
    }

    /** @return string a node the user may not share, such as one without the share permission */
    public static function shareNodeNotShareable(): string {
        return Translator::t('Nextcloud does not allow sharing this item.');
    }

    /** @return string a level above what the user may do with the node */
    public static function shareAboveOwnPermissions(): string {
        return Translator::t('You cannot give more access than you have on this item; share it with view instead.');
    }

    /** @return string the warning every plan of a public link carries */
    public static function linkPublicWarning(): string {
        return Translator::t('Anyone who has the link can open this, without signing in.');
    }

    /** @return string sharing turned off on the server */
    public static function shareDisabled(): string {
        return Translator::t('Sharing is turned off on this server.');
    }

    /** @return string sharing turned off for this account */
    public static function shareDisabledForYou(): string {
        return Translator::t('Your administrator turned sharing off for your account.');
    }

    /** @return string public links turned off by the administrator, for everyone or for the user's groups */
    public static function shareLinksDisabled(): string {
        return Translator::t('Your administrator turned public links off for your account.');
    }

    /** @return string sharing with groups turned off */
    public static function shareGroupsDisabled(): string {
        return Translator::t('Sharing with groups is turned off on this server.');
    }

    /** @return string the members-only rule refuses a group the user is not in */
    public static function shareOnlyOwnGroups(): string {
        return Translator::t('On this server you can only share with groups you are a member of.');
    }

    /** @return string the members-only rule refuses a person outside the user's groups */
    public static function shareOnlyGroupMembers(): string {
        return Translator::t('On this server you can only share with people who are in one of your groups.');
    }

    /**
     * @param string $group display name of the group
     * @return string warning of a group share
     */
    public static function shareGroupWarning(string $group): string {
        return Translator::t('Every member of %s gets access, including people who join the group later.', [$group]);
    }

    /**
     * @param string $recipient display name of the person or group
     * @return string warning of a folder shared with edit
     */
    public static function shareFolderEditWarning(string $recipient): string {
        return Translator::t('With edit on a folder, %s can also delete files inside it.', [$recipient]);
    }

    /** @return string the core says the recipient already has access through another share */
    public static function shareAlreadyHasAccess(): string {
        return Translator::t('This recipient already has access to this item through another share.');
    }

    /** @return string the core refused to remove a share */
    public static function unshareRefused(): string {
        return Translator::t('Nextcloud could not remove this share.');
    }

    /** @return string a share type the tools do not manage, such as a Talk attachment */
    public static function shareTypeUnsupported(): string {
        return Translator::t('This kind of share is not managed here; a Talk attachment is removed in Talk.');
    }

    /** @return string name shown for the recipient of a public link */
    public static function publicLink(): string {
        return Translator::t('Public link');
    }

    /** @return string a share asked for the root of the user folder */
    public static function shareRootRefused(): string {
        return Translator::t('Your whole folder cannot be shared; choose a file or a folder inside it.');
    }

    /** @return string a share asked for a node the user does not own */
    public static function shareNotOwner(): string {
        return Translator::t('Only files and folders you own can be shared here; this one belongs to someone else.');
    }

    /** @return string the administrator did not grant the sharing operation this type needs */
    public static function shareNotGranted(): string {
        return Translator::t('Your administrator has not allowed the AI to manage this kind of share.');
    }

    /** @return string description of files_list_shares */
    public static function listSharesTool(): string {
        return 'List the shares you created: with a path, the shares of that file or folder (which must be yours); '
            . 'without one, every share you created, ' . ShareAccess::PAGE_SIZE . ' per page '
            . 'with offset. Each item has shareId, type (user, group, link or room), with (id and display name), '
            . 'permission (view, edit or custom), reshare, expires, hasPassword, url (links only; null for a folder link while files are excluded from MCP access), removable and path. '
            . 'Passwords are never returned. A room share is a Talk attachment: it is listed with removable: false '
            . 'and is removed in Talk.';
    }

    /** @return string description of the path parameter of files_list_shares */
    public static function listSharesPath(): string {
        return 'File or folder of yours whose shares to list, e.g. /Documents/report.pdf. Omit to list every share you created.';
    }

    /** @return string description of the offset parameter of files_list_shares */
    public static function listSharesOffset(): string {
        return 'Shares to skip when listing without path; use the nextOffset of the previous page.';
    }

    /** @return string description of files_share */
    public static function shareTool(): string {
        return 'Share a file or folder of yours with a person or a group, or change how it is already shared with them. '
            . 'Find the account or group id with users_search first (include_groups: true for groups); never guess it. '
            . 'Sharing again with the same recipient changes that share instead of creating another one. permission is '
            . 'view (open and download) or edit (change; on a folder also add and delete inside it); a share never lets '
            . 'the recipient share it on. expires is the last day (YYYY-MM-DD, in the user\'s timezone, tomorrow or later); '
            . 'omit it to keep the current one, or for a new share to get the administrator\'s default. Without confirm: '
            . 'true it returns the plan and changes nothing. Nextcloud notifies the recipient. Needs the "share" permission. '
            . 'with: "link" creates or changes the public link of the file (one per file, view only, anyone with it can '
            . 'open it) and needs the "link" permission; its password is generated by the server when you pass '
            . 'password: true or the administrator requires one, and is returned once in the confirmed result only.';
    }

    /** @return string description of the with parameter of files_share */
    public static function shareWithParam(): string {
        return 'Recipient: user:<uid> for a person, group:<gid> for a group, or link for a public link.';
    }

    /** @return string description of the permission parameter of files_share */
    public static function sharePermissionParam(): string {
        return 'view to open and download; edit to change too (on a folder: add, change and delete inside it).';
    }

    /** @return string description of the expires parameter of files_share */
    public static function shareExpiresParam(): string {
        return 'Last day of access, YYYY-MM-DD in the user\'s timezone, tomorrow or later.';
    }

    /** @return string description of the note parameter of files_share */
    public static function shareNoteParam(): string {
        return 'Note shown to the recipient with the share; an empty string removes the current note.';
    }

    /** @return string description of the password parameter of files_share */
    public static function sharePasswordParam(): string {
        return 'Public link only: true makes the server generate a new password, which replaces the current one and is '
            . 'shown once in the result. Never choose a password yourself.';
    }

    /** @return string description of files_unshare */
    public static function unshareTool(): string {
        return 'Stop sharing a file or folder of yours: removes one share you created, so that person, group or public link '
            . 'loses access. Name the share with the shareId from files_list_shares, or with path plus with (user:<uid>, '
            . 'group:<gid> or link). Nothing is deleted: the file or folder and its content stay as they are. Only your own '
            . 'shares of your own files can be removed; a Talk attachment is removed in Talk. Without confirm: true it '
            . 'returns the plan and changes nothing. Needs the "share" permission; removing a public link needs "link".';
    }

    /** @return string description of the shareId parameter of files_unshare */
    public static function unshareIdParam(): string {
        return 'The shareId of the share to remove, exactly as files_list_shares returns it (for example ocinternal:123). '
            . 'Use it alone, or use path with with instead.';
    }

    /** @return string description of the path parameter of files_unshare */
    public static function unsharePathParam(): string {
        return 'File or folder of yours, e.g. /Documents/report.pdf. Use it together with with.';
    }

    /** @return string description of the with parameter of files_unshare */
    public static function unshareWithParam(): string {
        return 'Whose share to remove from path: user:<uid>, group:<gid> or link. Use it together with path.';
    }
}
