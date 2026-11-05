<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tasks\Presentation;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use DateTimeZone;
use IntlDateFormatter;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

/** Formats the existing plan values without changing their locale, timezone, escaping or comparison semantics. */
final class TaskPlanValues {
	/** Maximum excerpt length for long field values. */
	private const EXCERPT = 200;

	/**
	 * @param ParentPlanText|null $parents parent titles from the plan currently being rendered
	 */
	public function __construct(private ?ParentPlanText $parents = null) {
	}

	/**
	 * @param mixed $value raw plan value of one field
	 * @param string $field key of the field, which decides how its value is read
	 * @param DateTimeZone $zone timezone the plan names, deciding the hour a date reads as
	 * @return string|null the value a person reads, or null when it is empty
	 */
	public function value(mixed $value, string $field, DateTimeZone $zone): ?string {
		if ($field === 'percentComplete') {
			return is_numeric($value) ? $this->text($value) . '%' : null;
		}
		if ($field === 'priority') {
			return is_numeric($value) ? $this->priority((int) $value) : null;
		}
		if (in_array($field, ['start', 'due', 'completed'], true)) {
			return $this->date($value, $zone);
		}
		if ($field === 'categories') {
			return $this->categories($value);
		}
		if ($field === 'parent') {
			return ($this->parents ?? new ParentPlanText(null))->value($value);
		}
		$text = $this->text($value);

		return $text === '' ? null : PlanText::inline($text, self::EXCERPT);
	}

	/**
	 * A date of the task in the timezone of the reader: a due date must fall on the day they see.
	 *
	 * @param mixed $value raw plan value of a date field
	 * @param DateTimeZone $zone timezone the reader is in
	 * @return string|null the formatted date, or null when the field carries none
	 */
	private function date(mixed $value, DateTimeZone $zone): ?string {
		$text = $this->text($value);
		if ($text === '') {
			return null;
		}
		try {
			$date = new DateTimeImmutable($text);
		} catch (Exception) {
			return PlanText::inline($text, self::EXCERPT);
		}
		// A date without a time is a whole day for everybody; moving it to another zone would move the day.
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
			return $text;
		}

		// The person's own language decides the order of day and month: "02/10" is two different days in two countries.
		$formatted = (new IntlDateFormatter(Translator::locale(), IntlDateFormatter::MEDIUM, IntlDateFormatter::SHORT, $zone))->format($date);

		return is_string($formatted) ? $formatted : $date->setTimezone($zone)->format('Y-m-d H:i');
	}

	/**
	 * The categories of the task as one line, in the order the task keeps them.
	 *
	 * Each name is a complete quoted literal, so delimiters and whitespace cannot make two states read alike
	 * and a change at the end of a long name stays visible.
	 *
	 * @param mixed $value raw plan value of the categories field
	 * @return string|null the quoted categories separated by commas, or null when the task has none
	 */
	private function categories(mixed $value): ?string {
		$names = array_map(PlanText::quoted(...), $this->names($value));

		return $names === [] ? null : implode(', ', $names);
	}

	/**
	 * @param mixed $value raw plan value of the categories field
	 * @return list<string> the non-empty category names, in order
	 */
	public function names(mixed $value): array {
		return array_values(array_filter(array_map($this->text(...), is_array($value) ? $value : []), static fn (string $name) => $name !== ''));
	}

	/**
	 * @param int $priority iCalendar priority, 0 undefined and 1 the highest
	 * @return string translated priority
	 */
	private function priority(int $priority): string {
		// One literal per call: the translation tool extracts the source texts from here.
		return match (true) {
			$priority <= 0 => Translator::t('without priority'),
			$priority === 1 => Translator::t('highest priority'),
			$priority <= 4 => Translator::t('high priority'),
			$priority === 5 => Translator::t('normal priority'),
			$priority <= 8 => Translator::t('low priority'),
			default => Translator::t('lowest priority'),
		};
	}

	/**
	 * @param string $field key of a task field
	 * @return string translated field label
	 */
	public function label(string $field): string {
		// One literal per call: the translation tool extracts the source texts from here.
		return match ($field) {
			'summary' => Translator::t('Title'),
			'description' => Translator::t('Description'),
			'start' => Translator::t('Starts'),
			'due' => Translator::t('Due'),
			'priority' => Translator::t('Priority'),
			'percentComplete' => Translator::t('Progress'),
			'completed' => Translator::t('Completed at'),
			'categories' => Translator::t('Categories'),
			'parent' => Translator::t('Parent task'),
			default => Translator::t('Title'),
		};
	}

	/**
	 * @param mixed $value raw plan value
	 * @return string trimmed scalar text, empty for a non-scalar
	 */
	private function text(mixed $value): string {
		return is_scalar($value) ? trim((string) $value) : '';
	}

	/**
	 * The timezone the plan carries, resolved once and reused for every date of the body.
	 *
	 * A plan built without an account behind it, or one naming a zone this PHP build does not know,
	 * reads as UTC: the instant is right, only its hour is one the reader may not expect, which beats
	 * failing to describe the write at all.
	 *
	 * @param array<string, mixed> $plan the whole plan
	 * @return DateTimeZone timezone every date of this plan reads as
	 */
	public function zone(array $plan): DateTimeZone {
		$name = $this->text($plan['timezone'] ?? '');
		if ($name === '') {
			return new DateTimeZone('UTC');
		}
		try {
			return new DateTimeZone($name);
		} catch (Exception) {
			return new DateTimeZone('UTC');
		}
	}

	/**
	 * @param mixed $value raw plan value of a calendar
	 * @return string|null the display name, or null when the plan carries none
	 */
	public function name(mixed $value): ?string {
		if (!is_array($value)) {
			return null;
		}
		$name = $this->text($value['name'] ?? '');

		return $name === '' ? null : $name;
	}

	/**
	 * @param mixed $value raw plan value of a task
	 * @return array<string, mixed>|null the task fields, or null when the plan carries no task
	 */
	public function task(mixed $value): ?array {
		return is_array($value) && $value !== [] ? $value : null;
	}

}
