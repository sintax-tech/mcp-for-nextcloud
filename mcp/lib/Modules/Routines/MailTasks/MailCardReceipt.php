<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\MailTasks;

use OCA\Mcp\Modules\Routines\Presentation\MailCardMessages;
use OCA\Mcp\Modules\Routines\Presentation\RoutinesMessages;
use OCA\Mcp\Platform\Workflows\WorkflowStep;

/**
 * The receipt of one confirmed mail card: the saved file, then the card, with what each observed and why a step did
 * not start. It is built step by step and read once, so a stop always reports what exists.
 */
final class MailCardReceipt {
	/** The steps of a mail card, in the order they run. */
	public const STEPS = ['file', 'card'];
	/** A step that never ran. */
	public const NOT_STARTED = 'not_started';

	/** @var array<string, array<string, mixed>> the steps that ran or were refused, by name */
	private array $steps = [];

	/**
	 * @param string $step one of {@see self::STEPS}
	 * @param WorkflowStep $outcome what the port observed
	 * @return bool whether the mail card continues after this step
	 */
	public function record(string $step, WorkflowStep $outcome): bool {
		$this->steps[$step] = ['step' => $step, 'status' => $outcome->status, 'ids' => (object)$outcome->ids]
			+ ($outcome->message === null ? [] : ['message' => $outcome->message])
			+ ($outcome->result === [] ? [] : ['result' => $outcome->result]);
		return $outcome->status === WorkflowStep::APPLIED;
	}

	/**
	 * @param string $step one of {@see self::STEPS}
	 * @param string $reason why the step did not start, written for the user
	 * @return void
	 */
	public function skip(string $step, string $reason): void {
		$this->steps[$step] = ['step' => $step, 'status' => self::NOT_STARTED, 'ids' => (object)[], 'message' => $reason];
	}

	/**
	 * The receipt of a mail card the preflight refused: nothing was written.
	 *
	 * @param string $step the step that would be refused: email, file or card
	 * @param string $reason the refusal of that step
	 * @return array<string, mixed> the receipt
	 */
	public static function refused(string $step, string $reason): array {
		$receipt = new self();
		foreach (self::STEPS as $name) {
			$receipt->skip($name, $name === $step ? $reason : MailCardMessages::notStartedRefused());
		}
		return ['status' => 'refused', 'refused_at' => $step, 'message' => MailCardMessages::refused(MailCardMessages::stepName($step))]
			+ ($step === 'email' ? ['reason' => $reason] : []) + $receipt->items();
	}

	/**
	 * @return array<string, mixed> the receipt of a mail card that ran: completed when both steps applied, otherwise
	 *     stopped at the first step that did not, with the card not started after a file that was not saved
	 */
	public function build(): array {
		foreach (self::STEPS as $name) {
			if (!isset($this->steps[$name])) {
				$this->skip($name, RoutinesMessages::notStarted());
			}
		}
		foreach (self::STEPS as $name) {
			if ($this->steps[$name]['status'] !== WorkflowStep::APPLIED) {
				return ['status' => 'stopped', 'stopped_at' => $name, 'message' => MailCardMessages::stopped(MailCardMessages::stepName($name))] + $this->items();
			}
		}
		return ['status' => 'completed', 'message' => MailCardMessages::completed()] + $this->items();
	}

	/** @return array{steps: list<array<string, mixed>>, not_done: list<string>} every step in order, and those without effect */
	private function items(): array {
		$items = array_map(fn (string $name): array => $this->steps[$name], self::STEPS);
		$notDone = array_values(array_filter(self::STEPS, fn (string $name): bool => in_array($this->steps[$name]['status'], [self::NOT_STARTED, WorkflowStep::FAILED], true)));
		return ['steps' => $items, 'not_done' => $notDone];
	}
}
