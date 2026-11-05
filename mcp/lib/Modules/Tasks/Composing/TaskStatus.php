<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tasks\Composing;

use InvalidArgumentException;
use OCA\Mcp\L10n\Translator;
use Sabre\VObject\Component\VTodo;

/** Applies explicit VTODO status and progress together, following the Tasks app's status transitions. */
final class TaskStatus {
	/** @var list<string> the VTODO statuses accepted by create and edit */
	public const VALUES = ['NEEDS-ACTION', 'IN-PROCESS', 'COMPLETED', 'CANCELLED'];

	/**
	 * Validates the pair before changing the cloned task. Progress alone stays in TaskPatch's existing branch.
	 *
	 * @param VTodo $task cloned task master
	 * @param mixed $status explicit status, one of self::VALUES
	 * @param mixed $percent explicit progress, or null when omitted
	 * @param string $stamp UTC timestamp of this write
	 * @return void
	 * @throws InvalidArgumentException when the status or progress is invalid or the pair contradicts itself
	 */
	public function apply(VTodo $task, mixed $status, mixed $percent, string $stamp): void {
		if (!is_string($status) || !in_array($status, self::VALUES, true)) {
			throw new InvalidArgumentException(Translator::t('Task status must be one of %s.', [implode(', ', self::VALUES)]));
		}
		if ($percent !== null && (!is_int($percent) || $percent < 0 || $percent > 100)) {
			throw new InvalidArgumentException(Translator::t('Task progress must be between 0 and 100.'));
		}
		if ($status === 'COMPLETED' && $percent !== null && $percent !== 100) {
			throw new InvalidArgumentException(Translator::t('A completed task must have 100 percent progress.'));
		}
		if (in_array($status, ['NEEDS-ACTION', 'IN-PROCESS'], true) && $percent === 100) {
			throw new InvalidArgumentException(Translator::t('Only a completed or cancelled task can have 100 percent progress.'));
		}
		$current = (int) (string) $task->{'PERCENT-COMPLETE'};
		if ($status === 'COMPLETED') {
			// An already completed task keeps its original completion time, including a lower-case stored status.
			if (strtoupper((string) $task->STATUS) !== 'COMPLETED' || !isset($task->COMPLETED)) {
				$task->COMPLETED = $stamp;
			}
			$percent = 100;
		} elseif ($status !== 'CANCELLED') {
			unset($task->COMPLETED);
			$percent ??= $current === 100 ? 99 : ($status === 'IN-PROCESS' && $current === 0 ? 1 : $current);
		}
		$task->STATUS = $status;
		// Explicit status does not add a default zero property; an existing property is still updated.
		if ($percent !== null && ($percent !== 0 || isset($task->{'PERCENT-COMPLETE'}))) {
			$task->{'PERCENT-COMPLETE'} = $percent;
		}
	}
}
