<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
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

    /** @return string translated fallback label when a share has no identifiable sharer */
    public static function unidentified(): string {
        return Translator::t('unidentified');
    }

    /**
     * The `confirm` argument of every writing tool, published by the registry itself.
     *
     * It says what the model has to do, not what the server stores: nothing is kept between the two calls.
     *
     * @return string the description the model reads in the schema
     */
    public static function confirmParameter(): string {
        return Translator::t('Send true only after you have shown the plan of this call to the user and the user explicitly said yes. Without it nothing is changed and the answer is the plan.');
    }

    /**
     * The `plan_state` argument of a write that binds its confirmation to the state its plan showed ({@see \OCA\Mcp\Tools\PlanState}).
     * Fixed English, like every description of a module schema; only the `confirm` the registry adds is translated.
     *
     * @return string the description the model reads in the schema
     */
    public static function planStateParameter(): string {
        return 'Opaque value of the plan. Send it back unchanged together with confirm: true. If the state changed since the plan, nothing is written and the answer is the new plan.';
    }

    /**
     * Closing sentence of the plan of a tool whose module does not describe its own writes.
     *
     * @return string what the model is asked to do with the plan
     */
    public static function planNothingChanged(): string {
        return Translator::t('Nothing was changed. Show this plan to the user, ask whether they want it done, and only then repeat the same call with confirm: true.');
    }

    /** @return string translated shared-write instruction requiring user confirmation */
    public static function confirmAdvice(): string {
        return Translator::t('Changes affect other people. Confirm with the user before continuing and repeat the call with confirm_shared: true.');
    }

    /** @return string translated confirmation instruction for a consumed single-use checkout link */
    public static function confirmAdviceCheckout(): string {
        return Translator::t('Changes affect other people. Confirm with the user before continuing and run a new files_checkout with confirm_shared: true, because this edit link has already been used.');
    }

    /**
     * The confirmation a create link of files_upload asks for when its folder became shared after it was issued. The
     * link is not spent, but it was issued without confirm_shared and can never carry it, so the way on is a new one.
     *
     * @return string translated confirmation instruction
     */
    public static function confirmAdviceUpload(): string {
        return Translator::t('Changes affect other people. Confirm with the user before continuing and run a new files_upload with confirm_shared: true; this upload link was issued without that confirmation.');
    }

    /** @return string translated message when a resource cannot be found in Nextcloud */
    public static function notFound(): string {
        return Translator::t('Resource not found in Nextcloud.');
    }

    /** @return string translated message when the user has no access to a resource */
    public static function forbidden(): string {
        return Translator::t('No access to this resource in Nextcloud.');
    }

    /** @return string translated retry message when another operation holds the resource lock */
    public static function locked(): string {
        return Translator::t('Resource locked by another operation; try again.');
    }

    /** @return string translated message when the resource ETag changed since it was read */
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
