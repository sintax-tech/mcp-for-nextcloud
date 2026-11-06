<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\DueDates;

use OCA\Mcp\L10n\Translator;

/**
 * Input guidance and safe translated refusals for calendar days and timed Deck deadlines.
 */
final class CardDueDateMessages {
	/**
	 * Public schema guidance; an offset means an instant, otherwise the account timezone applies.
	 */
	public const INPUT_DESCRIPTION = 'Due date as YYYY-MM-DD, YYYY-MM-DDTHH:MM[:SS] in the account timezone, or a timestamp with Z or an explicit offset (±HH:MM). Send null to remove it.';

	/**
	 * Shared module instructions: output compatibility and the unchanged day-based filters.
	 */
	public const GUIDE_NOTE = 'duedate accepts YYYY-MM-DD (local midnight), YYYY-MM-DDTHH:MM[:SS] in the account timezone, or a timestamp with Z/±HH:MM. Nonexistent local times are refused; repeated local times use the first occurrence. Output duedate remains the local date and dueAt gives the instant with the account offset, without a timezone field. overdue is based on the local day, not the time; dueBefore accepts only YYYY-MM-DD.';

	/**
	 * Describe the exact supported input forms without echoing an invalid value.
	 *
	 * @return string Translated invalid deadline message.
	 */
	public static function invalid(): string {
		return Translator::t('The due date must be YYYY-MM-DD or YYYY-MM-DDTHH:MM[:SS], optionally followed by Z or an offset ±HH:MM. Use a real date and time.');
	}

	/**
	 * Explain a missing local clock time without silently changing the requested hour.
	 *
	 * @return string Translated daylight-saving gap refusal.
	 */
	public static function nonexistent(): string {
		return Translator::t('This local due time does not exist in the account timezone. Choose another time or include an explicit offset.');
	}
}
