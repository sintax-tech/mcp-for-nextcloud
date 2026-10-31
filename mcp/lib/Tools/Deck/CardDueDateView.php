<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Deck;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

/**
 * Account-local deadline output and human-readable plans, separate from strict parsing/native storage.
 */
final class CardDueDateView {
	/**
	 * Serialize a native datetime with the account offset while leaving the existing day field unchanged.
	 *
	 * @param mixed $value Native Card::getDuedate value.
	 * @param DateTimeZone $zone Account timezone.
	 * @return string|null ISO 8601 including seconds and account offset, or null.
	 */
	public static function dueAt(mixed $value, DateTimeZone $zone): ?string {
		return $value instanceof DateTimeInterface
			? DateTimeImmutable::createFromInterface($value)->setTimezone($zone)->format(DateTimeInterface::ATOM)
			: null;
	}

	/**
	 * Canonicalize requested input in the same account zone used by the edit plan's current value.
	 *
	 * @param mixed $value Requested supported deadline or null.
	 * @param DateTimeZone $zone Account timezone.
	 * @return string|null Local ISO instant; equal instants have identical plan values.
	 */
	public static function planValue(mixed $value, DateTimeZone $zone): ?string {
		return self::dueAt(CardDueDate::parse(CardDueDate::validate($value, $zone), $zone), $zone);
	}

	/**
	 * Show a deadline with IANA zone and offset when timed or repeated, retaining a plain unambiguous midnight day.
	 *
	 * Seconds appear only when nonzero. A repeated midnight includes its selected offset so approval is unambiguous.
	 * Malformed legacy plan data is shown inert, never interpreted as Markdown or a new input format.
	 *
	 * @param string $raw Deadline in a plan.
	 * @param DateTimeZone $zone Account/display timezone.
	 * @return string Safe localized display text.
	 */
	public static function formatPlan(string $raw, DateTimeZone $zone): string {
		try {
			$date = CardDueDate::parse($raw, $zone)?->setTimezone($zone);
			if ($date->format('H:i:s') === '00:00:00' && !CardDueDate::ambiguous($date, $zone)) {
				return $date->format('Y-m-d');
			}
			return Translator::t('%s (%s, UTC%s)', [
				$date->format($date->format('s') === '00' ? 'Y-m-d H:i' : 'Y-m-d H:i:s'),
				PlanText::inline($zone->getName()),
				$date->format('P'),
			]);
		} catch (\Throwable) {
			return PlanText::inline($raw);
		}
	}
}
