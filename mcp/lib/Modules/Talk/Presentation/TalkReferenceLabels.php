<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Talk\Presentation;

use OCA\Mcp\L10n\Translator;

/** Existing localized labels and missing-title fallback shared by reference text and plan rendering. */
final class TalkReferenceLabels {
	/** Static translated reference text only. */
	private function __construct() {
	}

	/**
	 * Label of the line a Deck reference adds to a message, before the title of the item.
	 * @return string The label in the language of the current user.
	 */
	public static function referenceLabelDeck(): string {
		return Translator::t('Deck card');
	}

	/**
	 * Label of the line a calendar reference adds to a message, before the title of the item.
	 * @return string The label in the language of the current user.
	 */
	public static function referenceLabelCalendar(): string {
		return Translator::t('Calendar event');
	}

	/** Title shown for an event without SUMMARY. @return string The title in the language of the current user. */
	public static function referenceUntitled(): string {
		return Translator::t('(no title)');
	}
}
