<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Common;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\ToolFailure;

/**
 * User-facing strings of the classes in Tools/Common, which every module shares.
 *
 * The confirmation sentences live here and not in SharedWriteGuard so that Files, Notes, Deck and Calendar
 * cannot drift apart: the whole point of the shared guard is that the agent sees byte-identical JSON and
 * wording whichever module answered.
 */
final class CommonMessages {
    /** Used when a share carries no sharer at all, so there is no uid to name. */
    public const UNIDENTIFIED = 'unidentified';
    /** Suffix shared by every confirmation message, in every module. */
    public const CONFIRM_ADVICE = 'Changes affect other people. Confirm with the user before continuing and repeat the call with confirm_shared: true.';
    /**
     * Same advice for the checkout upload, where the call cannot simply be repeated: the link is single
     * use and is already spent by the time the confirmation is produced.
     */
    public const CONFIRM_ADVICE_CHECKOUT = 'Changes affect other people. Confirm with the user before continuing and run a new files_checkout with confirm_shared: true, because this edit link has already been used.';

    public static function unidentified(): string {
        return Translator::t('unidentified');
    }

    public static function confirmAdvice(): string {
        return Translator::t('Changes affect other people. Confirm with the user before continuing and repeat the call with confirm_shared: true.');
    }

    public static function confirmAdviceCheckout(): string {
        return Translator::t('Changes affect other people. Confirm with the user before continuing and run a new files_checkout with confirm_shared: true, because this edit link has already been used.');
    }

    public static function notFound(): string {
        return Translator::t('Resource not found in Nextcloud.');
    }

    public static function forbidden(): string {
        return Translator::t('No access to this resource in Nextcloud.');
    }

    public static function locked(): string {
        return Translator::t('Resource locked by another operation; try again.');
    }

    public static function conflict(): string {
        return Translator::t('The resource has changed since the last read (etag differs); nothing was written.');
    }

    /**
     * @param string $teamFolder name of the team folder the node lives in
     * @return string the sentence naming a team folder
     */
    public static function teamFolder(string $teamFolder): string {
        return Translator::t('This file is in the team folder \'%s\' (Team Folder).', [$teamFolder]);
    }

    /**
     * @param string $sharedBy display name of whoever shared the node
     * @return string the sentence naming the sharer
     */
    public static function sharedBy(string $sharedBy): string {
        return Translator::t('This file was shared by %s.', [$sharedBy]);
    }

    /** @return string the sentence for a node in an external storage, which has no person to name */
    public static function externalStorage(): string {
        return Translator::t('This file is on an external storage.');
    }

    /** @return string insufficient quota */
    public static function insufficientQuota(): string {
        return Translator::t('Insufficient space in the user quota.');
    }

    /** @return string a node that is not a file */
    public static function notAFile(): string {
        return Translator::t('The specified path is not a file.');
    }

    /** @return string a file already exists with folder name */
    public static function fileInTargetFolderPath(): string {
        return Translator::t('A file already exists with the name of the destination folder.');
    }
}
