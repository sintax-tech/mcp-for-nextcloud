<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Presentation;

use OCA\Mcp\L10n\Translator;

/**
 * Localized refusals of the paged Talk reads: page size, continuation cursor and the read marker they start from.
 *
 * Each method translates its unchanged English source text for the authenticated user through Translator.
 */
final class PageMessages {
	/**
	 * Prevents construction of this static presentation utility.
	 */
	private function __construct() {
	}

	/**
	 * Page size for talk_list_unread, between 1 and 50.
	 * @return string The message in the language of the current user.
	 */
	public static function invalidPageLimit(): string {
		return Translator::t('limit must be a number between 1 and 50');
	}

	/**
	 * Opaque cursor for talk_list_unread or talk_read_unread that the tool did not issue for this reader.
	 * @return string The message in the language of the current user.
	 */
	public static function invalidCursor(): string {
		return Translator::t('invalid cursor');
	}

	/**
	 * A federated conversation keeps its messages on the server that hosts it, where this server cannot read them.
	 * @return string The message in the language of the current user.
	 */
	public static function federatedUnread(): string {
		return Translator::t('This conversation is hosted on another server: its unread messages cannot be read here.');
	}

	/**
	 * The read marker could not be read, so there is no starting point that would not skip or repeat messages.
	 * @return string The message in the language of the current user.
	 */
	public static function unreadableMarker(): string {
		return Translator::t('The read marker of this conversation could not be read.');
	}

	/**
	 * The message a continuation started after is gone, so the next page cannot be placed without skipping messages.
	 * @return string The message in the language of the current user.
	 */
	public static function continuationLost(): string {
		return Translator::t('The message this read continued from is no longer in the conversation. Call again without cursor to start from the read marker.');
	}
}
