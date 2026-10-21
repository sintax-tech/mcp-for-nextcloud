<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Execution;

use OCA\Mcp\L10n\Translator;

class ErrorMessages {
    /**
     * Shared by "not found" and "no permission": the Deck answers both with the same exception.
     * @return string The message in the language of the current user.
     */
    public static function errorNotFoundOrForbidden(): string {
        return Translator::t('Card, list or board not found, or no permission.');
    }

    /**
     * Board or card archived.
     * @return string The message in the language of the current user.
     */
    public static function errorNotAllowed(): string {
        return Translator::t('Operation not allowed on this Deck board or card.');
    }

    /**
     * A list is deleted only when it holds no card, active or archived.
     * @param int $cards Cards the list holds.
     * @return string The message in the language of the current user.
     */
    public static function errorStackNotEmpty(int $cards): string {
        return Translator::n(
            'The list has %n card; move or delete it first.',
            'The list has %n cards; move or delete them first.',
            $cards,
        );
    }

    /**
     * A board is deleted only when none of its lists holds a card, active or archived.
     * @param int $cards Cards the board holds.
     * @return string The message in the language of the current user.
     */
    public static function errorBoardNotEmpty(int $cards): string {
        return Translator::n(
            'The board has %n card; move or delete it first.',
            'The board has %n cards; move or delete them first.',
            $cards,
        );
    }

    /**
     * A card was created in a list while it was being deleted, so the delete was taken back.
     * @param bool $restored Whether the list is back; false when it stayed in the Deck trash.
     * @return string The message in the language of the current user.
     */
    public static function errorStackReceivedCards(bool $restored): string {
        return $restored ? Translator::t('The list received cards while it was being deleted; nothing was deleted.') : Translator::t('The list received cards while it was being deleted and could not be restored; recover it from the Deck trash.');
    }

    /**
     * A card was created in a list of the board while it was being deleted, so the delete was taken back.
     * @param bool $restored Whether the board is back; false when it stayed in the Deck trash.
     * @return string The message in the language of the current user.
     */
    public static function errorBoardReceivedCards(bool $restored): string {
        return $restored ? Translator::t('The board received cards while it was being deleted; nothing was deleted.') : Translator::t('The board received cards while it was being deleted and could not be restored; recover it from the Deck trash.');
    }

    /**
     * Only the owner deletes a board, even when others may manage it.
     * @return string The message in the language of the current user.
     */
    public static function errorBoardNotOwned(): string {
        return Translator::t('Only the owner of a board can delete it.');
    }

    /**
     * The Deck validator rejected the payload.
     * @return string The message in the language of the current user.
     */
    public static function errorInvalid(): string {
        return Translator::t('Invalid data for Deck.');
    }

    /**
     * The card changed between the read and the write.
     * @return string The message in the language of the current user.
     */
    public static function errorConflict(): string {
        return Translator::t('The Deck item changed during the operation. Try again.');
    }

    /**
     * The session the Deck services read is not the authenticated caller's; refused before writing.
     * @return string The message in the language of the current user.
     */
    public static function errorSessionNotBound(): string {
        return Translator::t('The Deck could not be bound to the authenticated account. Nothing was changed; try again.');
    }

    /**
     * Fallback for anything unexpected.
     * @return string The message in the language of the current user.
     */
    public static function errorGeneric(): string {
        return Translator::t('Could not complete the operation in Deck.');
    }

    /**
     * Raised when deck_edit_card is called without a single editable field.
     * @return string The message in the language of the current user.
     */
    public static function errorNoFieldToEdit(): string {
        return Translator::t('Provide at least one field to edit: title, description, duedate, assign or unassign.');
    }

    /**
     * Raised when a date is not a real calendar date in YYYY-MM-DD form.
     * @return string The message in the language of the current user.
     */
    public static function errorInvalidDuedate(): string {
        return Translator::t('The due date must be a valid date in YYYY-MM-DD format.');
    }

    /**
     * Raised when `dueBefore` is not a real calendar date in YYYY-MM-DD form.
     * @return string The message in the language of the current user.
     */
    public static function errorInvalidDueBefore(): string {
        return Translator::t('The dueBefore date must be a valid date in YYYY-MM-DD format.');
    }

    /**
     * Raised when `deck_followup_cards` is called with a status the Deck does not group by.
     * @return string The message in the language of the current user.
     */
    public static function errorInvalidStatus(): string {
        return Translator::t('Invalid status for the follow-up. Use overdue, open, done or all.');
    }

    /**
     * Raised when a description is longer than the module accepts.
     * @return string The message in the language of the current user.
     */
    public static function errorDescriptionTooLong(): string {
        return Translator::t('The card description exceeds the maximum size of 100000 characters.');
    }

    /**
     * Raised when the title is empty once trimmed.
     * @return string The message in the language of the current user.
     */
    public static function errorTitleRequired(): string {
        return Translator::t('The card title cannot be empty.');
    }

    /**
     * Raised for a tool name this module does not serve.
     * @return string The message in the language of the current user.
     */
    public static function errorUnknownTool(): string {
        return Translator::t('Unknown Deck tool.');
    }

    /**
     * Raised when the Deck app is not available to the caller.
     * @return string The message in the language of the current user.
     */
    public static function errorDeckAppUnavailable(): string {
        return Translator::t('The Deck app is not available for this user.');
    }
}
