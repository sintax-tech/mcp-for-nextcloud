<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tasks\Presentation;

use DateTimeZone;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

/** Adds status and completion-date changes to create/edit plans, using the existing date and timezone formatter. */
final class TaskStatusPlanText {
	/**
	 * @param list<string> $lines existing create-plan lines
	 * @param array<string, mixed> $after proposed task fields
	 * @param DateTimeZone $zone timezone carried by the plan
	 * @return list<string> lines including non-default status and completion date
	 */
	public function create(array $lines, array $after, DateTimeZone $zone): array {
		$status = $this->status($after['status'] ?? null);
		if ($status !== null && strtoupper((string) ($after['status'] ?? '')) !== 'NEEDS-ACTION') {
			$lines[] = '- ' . Translator::t('Status') . ': ' . $status;
		}
		$values = new TaskPlanValues();
		$date = $this->date($after['completed'] ?? null, $zone);
		if ($date !== null) {
			$lines[] = '- ' . $values->label('completed') . ': ' . $date;
		}
		return $lines;
	}

	/**
	 * @param list<string> $lines existing edit-plan lines
	 * @param array<string, mixed> $before current task fields
	 * @param array<string, mixed> $after proposed task fields
	 * @param DateTimeZone $zone timezone carried by the plan
	 * @return list<string> lines including changes to status and completion date
	 */
	public function edit(array $lines, array $before, array $after, DateTimeZone $zone): array {
		$from = $this->status($before['status'] ?? null);
		$to = $this->status($after['status'] ?? null);
		if ($from !== $to) {
			$lines[] = $this->change(Translator::t('Status'), $from, $to);
		}
		// Compare the stored moments, so even a change within the formatter's displayed minute gets a line.
		if (($before['completed'] ?? null) !== ($after['completed'] ?? null)) {
			$values = new TaskPlanValues();
			$fromDate = $this->date($before['completed'] ?? null, $zone);
			$toDate = $this->date($after['completed'] ?? null, $zone);
			if ($fromDate !== null && $fromDate === $toDate) {
				$fromDate = $this->seconds($before['completed'], $zone);
				$toDate = $this->seconds($after['completed'], $zone);
			}
			$lines[] = $this->change($values->label('completed'), $fromDate, $toDate);
		}
		return $lines;
	}

	/**
	 * @param mixed $value completion timestamp from the plan
	 * @param DateTimeZone $zone plan timezone
	 * @return string|null existing date format, with its ISO fallback when intl is unavailable
	 * @throws \Error when the existing formatter fails for a reason other than the missing intl extension
	 */
	private function date(mixed $value, DateTimeZone $zone): ?string {
		try {
			return (new TaskPlanValues())->value($value, 'completed', $zone);
		} catch (\Error $error) {
			if (class_exists(\IntlDateFormatter::class)) { throw $error; }
			return (new \DateTimeImmutable((string) $value))->setTimezone($zone)->format('Y-m-d H:i');
		}
	}

	/**
	 * @param mixed $value raw completion timestamp whose usual display conceals a change
	 * @param DateTimeZone $zone the same timezone used for the ordinary date text
	 * @return string|null date including seconds, or the ordinary escaped text for an invalid timestamp
	 */
	private function seconds(mixed $value, DateTimeZone $zone): ?string {
		try {
			return (new \DateTimeImmutable((string) $value))->setTimezone($zone)->format('Y-m-d H:i:s');
		} catch (\Exception) {
			return $this->date($value, $zone);
		}
	}

	/**
	 * @param string $label translated field label
	 * @param string|null $from current formatted value
	 * @param string|null $to proposed formatted value
	 * @return string one before/after line, naming empty values explicitly
	 */
	private function change(string $label, ?string $from, ?string $to): string {
		return '- ' . $label . ': ' . ($from ?? Translator::t('empty')) . ' → ' . ($to ?? Translator::t('empty'));
	}

	/**
	 * @param mixed $value stored status, canonical or lower-case
	 * @return string|null translated status or escaped unknown value, null when absent
	 */
	private function status(mixed $value): ?string {
		$status = is_scalar($value) ? trim((string) $value) : '';
		return match (strtoupper($status)) {
			'' => null,
			'NEEDS-ACTION' => Translator::t('needs action'),
			'IN-PROCESS' => Translator::t('in progress'),
			'COMPLETED' => Translator::t('completed'),
			'CANCELLED' => Translator::t('cancelled'),
			default => PlanText::inline($status, 200),
		};
	}
}
