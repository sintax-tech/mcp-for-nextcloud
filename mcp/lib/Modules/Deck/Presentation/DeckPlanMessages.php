<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Presentation;

use OCA\Mcp\L10n\Translator;

/** Keeps the existing Deck plan text unchanged. */
final class DeckPlanMessages {
	/** @return string Localized promise that unrelated label associations remain unchanged. */
	public static function planLabels(): string {
		return Translator::t('Only the requested label associations will change. All other labels will be preserved.');
	}

	/**
	 * @return string The message in the language of the current user.
	 */
	public static function planCreate(): string {
		return Translator::t('Nothing was changed. After your approval a new card is created at the end of the list below, owned by you. You are not assigned to it unless listed below.');
	}

	/**
	 * @return string The message in the language of the current user.
	 */
	public static function planEdit(): string {
		return Translator::t('Nothing was changed. After your approval the fields below are written over the card.');
	}

	/**
	 * @return string The message in the language of the current user.
	 */
	public static function planMove(): string {
		return Translator::t('Nothing was changed. After your approval the card moves from the list below to the destination below.');
	}

	/**
	 * @return string The message in the language of the current user.
	 */
	public static function planDelete(): string {
		return Translator::t('Nothing was changed. After your approval the card is deleted from the board below.');
	}

	/**
	 * @return string The message in the language of the current user.
	 */
	public static function planCreateBoard(): string {
		return Translator::t('Nothing was changed. After your approval the board below is created for you, with its lists and cards, in this order. Nothing is undone if a step fails midway; the result says what exists.');
	}

	/**
	 * @return string The message in the language of the current user.
	 */
	public static function planCreateStack(): string {
		return Translator::t('Nothing was changed. After your approval a new list is created on the board below.');
	}

	/**
	 * @return string The message in the language of the current user.
	 */
	public static function planDeleteStack(): string {
		return Translator::t('Nothing was changed. After your approval the empty list below is deleted.');
	}

	/**
	 * @return string The message in the language of the current user.
	 */
	public static function planDeleteBoard(): string {
		return Translator::t('Nothing was changed. After your approval the empty board below is deleted.');
	}

	/**
	 * @return string The message in the language of the current user.
	 */
	public static function planDeleteStackConsequence(): string {
		return Translator::t('The list goes to the trash of the board and can be recovered there, in the Deck web interface.');
	}

	/**
	 * @return string The message in the language of the current user.
	 */
	public static function planDeleteBoardConsequence(): string {
		return Translator::t('The board, with its empty lists, goes to the Deck trash and can be recovered there, in the Deck web interface.');
	}

	/**
	 * @return string The message in the language of the current user.
	 */
	public static function planDeleteConsequence(): string {
		return Translator::t('Deck deletes the card the way the web interface does: it leaves the lists, and only the board owner or an administrator can bring it back from the Deck web interface. No tool of this app undoes it.');
	}

	/** Constant and translated-message holder. */
	private function __construct() {
	}
}
