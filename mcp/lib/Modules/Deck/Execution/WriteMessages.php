<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Execution;

use OCA\Mcp\L10n\Translator;

class WriteMessages {
    /**
     * Deck saved the write and failed afterwards, in an activity, event or notification: the result stands.
     * @return string The message in the language of the current user.
     */
    public static function writtenThenFailed(): string {
        return Translator::t('Saved; Deck reported an error afterwards (notification or activity).');
    }

    /**
     * A creation threw and the read-back found an item that only looks like it (same title, owner and moment, or a
     * list or board that was not in the snapshot): it may belong to another call, so nothing is built on it.
     * @return string The message in the language of the current user.
     */
    public static function probablyCreated(): string {
        return Translator::t('Probably created: check it before continuing.');
    }

    /**
     * Assignees of a card that was only probably created are left alone.
     * @return string The message in the language of the current user.
     */
    public static function probableCardNotAssigned(): string {
        return Translator::t('The assignees were not assigned because the card was only probably created. Check it first.');
    }

    /**
     * Reason of a list or card of deck_create_board that was not created because the board or list it belongs to
     * was found only by a likeness.
     * @return string The message in the language of the current user.
     */
    public static function probableParentNotBuilt(): string {
        return Translator::t('Not created: what it belongs to was only probably created. Check it, then create this one.');
    }

    /**
     * A write threw and the state could not be read back: unknown, so read before retrying instead of repeating.
     * @param string $readWith Tool that reads the item back.
     * @return string The message in the language of the current user.
     */
    public static function writeUnconfirmed(string $readWith): string {
        return Translator::t(
            'Could not confirm whether Deck saved this change; read it with %s before trying again.',
            [$readWith],
        );
    }

    /**
     * One list or card of deck_create_board whose creation could not be confirmed.
     * @param string $kind `list` or `card`.
     * @param string $title Its title.
     * @return string The message in the language of the current user.
     */
    public static function boardItemUnconfirmed(string $kind, string $title): string {
        return $kind === 'list' ? Translator::t('Could not confirm whether the list \'%s\' was created; read the board before retrying.', [$title]) : Translator::t('Could not confirm whether the card \'%s\' was created; read the list before retrying.', [$title]);
    }

    /**
     * Message returned, without error, instead of writing on somebody else's board.
     * @param string $board Name of the board.
     * @param string $owner Display name of its owner.
     * @return string The message in the language of the current user.
     */
    public static function sharedConfirmation(string $board, string $owner): string {
        return Translator::t(
            'The board \'%s\' belongs to %s and is shared with you. Changes affect other people. Confirm with the user before continuing and repeat the call with confirm_shared: true.',
            [$board, $owner],
        );
    }
}
