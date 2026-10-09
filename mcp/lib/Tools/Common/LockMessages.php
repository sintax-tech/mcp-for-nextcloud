<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Common;

use OCA\Mcp\L10n\Translator;

/**
 * Sentences that explain a file lock of files_lock, shared by every module that writes files. {@see LockAwareWrite}
 * puts them together: who holds the lock, since when, until when, and what the person can do about it.
 */
final class LockMessages {
    /**
     * @param string $path user-relative path of the file
     * @param string $app name of the app holding the lock, e.g. Text or Nextcloud Office
     * @return string an editor app holds the lock
     */
    public static function lockedByApp(string $path, string $app): string {
        return Translator::t('The file “%s” is locked by %s, usually because it is open there.', [$path, $app]);
    }

    /** @param string $path user-relative path of the file @return string an app without a known name holds the lock */
    public static function lockedByUnnamedApp(string $path): string {
        return Translator::t('The file “%s” is locked by an app, usually because it is open in an editor.', [$path]);
    }

    /**
     * @param string $path user-relative path of the file
     * @param string $person display name of whoever locked it
     * @return string another person holds the lock
     */
    public static function lockedBy(string $path, string $person): string {
        return Translator::t('The file “%s” is locked by %s.', [$path, $person]);
    }

    /** @param string $path user-relative path of the file @return string the user themselves holds the lock */
    public static function lockedByYou(string $path): string {
        return Translator::t('The file “%s” is locked by you.', [$path]);
    }

    /** @param string $path user-relative path of the file @return string nobody the app can name holds the lock */
    public static function lockedBySomeoneElse(string $path): string {
        return Translator::t('The file “%s” is locked by someone else.', [$path]);
    }

    /** @param string $path user-relative path of the file @return string a refusal that does not tell who holds the lock */
    public static function lockedUnidentified(string $path): string {
        return Translator::t('The file “%s” is locked, and Nextcloud does not say by whom.', [$path]);
    }

    /** @param string $folder user-relative path of the folder @return string a file the user cannot see, inside the folder, is locked */
    public static function lockedInside(string $folder): string {
        return Translator::t('A file inside the folder “%s” is locked.', [$folder]);
    }

    /** @param string $path user-relative path of the file or folder @return string the lock could not be read, so the write is refused */
    public static function unverified(string $path): string {
        return Translator::t('Could not check whether the file “%s” is locked, so nothing was changed. Try again in a moment.', [$path]);
    }

    /**
     * @param string $folder user-relative path of the folder
     * @param int $limit most items checked
     * @return string the folder holds too many items to check them all
     */
    public static function tooManyToCheck(string $folder, int $limit): string {
        return Translator::t('The folder “%s” has more than %s items, too many to check for locked files, so nothing was changed. Move it in smaller parts.', [$folder, $limit]);
    }

    /** @param string $when date and time in the user's timezone @return string when the lock was taken */
    public static function since(string $when): string {
        return Translator::t('Locked since %s.', [$when]);
    }

    /** @param string $when date and time in the user's timezone @return string when the lock runs out */
    public static function endsAt(string $when): string {
        return Translator::t('The lock ends at %s, unless it is renewed.', [$when]);
    }

    /** @param int $minutes whole minutes left, rounded up @return string how long the lock still lasts */
    public static function endsIn(int $minutes): string {
        return Translator::t('The lock ends in about %s minutes, unless it is renewed.', [$minutes]);
    }

    /** @return string the lock has no expiry */
    public static function noEnd(): string {
        return Translator::t('The lock has no end time.');
    }

    /** @return string what to do about an editor that holds the file */
    public static function adviceApp(): string {
        return Translator::t('Close the file in that app (for a note, also in Notes) and try again. Nextcloud does not say who has it open.');
    }

    /** @return string what to do about a person who locked the file */
    public static function advicePerson(): string {
        return Translator::t('Ask that person to unlock it, or wait until the lock ends, and then try again.');
    }

    /** @return string what to do about a WebDAV client that locked the file */
    public static function adviceToken(): string {
        return Translator::t('It was locked through a WebDAV client, such as the desktop client or an office app. Close it there, or wait until the lock ends, and then try again.');
    }

    /** @return string what to do about a lock whose holder is not known */
    public static function adviceUnknown(): string {
        return Translator::t('Close it in any editor where it is open, or ask whoever locked it to unlock it, and then try again.');
    }

    /** @return string what to do about the user's own lock, on a route that cannot act as the user */
    public static function adviceOwn(): string {
        return Translator::t('You locked this file yourself. Unlock it in Nextcloud Files, or change it with files_edit, which keeps your lock.');
    }

    /** @return string closing sentence of a refusal decided before anything was written */
    public static function nothingChanged(): string {
        return Translator::t('Nothing was changed.');
    }

    /** @return string what a plan says about a lock that would refuse the confirmed call */
    public static function planRefused(): string {
        return Translator::t('While the lock lasts the change is refused, even after confirmation.');
    }

    /** @param string $path user-relative path of the file @return string what a plan says about the user's own lock */
    public static function planOwn(string $path): string {
        return Translator::t('The file “%s” is locked by you. This change is allowed and keeps your lock.', [$path]);
    }

    /** @return string a lock refusal that arrives without the file it concerns */
    public static function lockedWithoutDetails(): string {
        return Translator::t('The file is locked: it is open in an editor or another person locked it. Close it, or ask whoever locked it to unlock it, and then try again.');
    }

    /** @return string what follows a lock refusal on an upload link that is already spent */
    public static function newCheckoutNeeded(): string {
        return Translator::t('This upload link has already been used: once the lock ends, run a new files_checkout.');
    }
}
