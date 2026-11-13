<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Availability\Editing;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Availability\Integration\AbsenceGateway;
use OCA\Mcp\Modules\Availability\Integration\AbsenceWriter;
use OCA\Mcp\Modules\Availability\Presentation\AvailabilityMessages;
use OCA\Mcp\Modules\Availability\Reading\AbsenceView;
use Throwable;

/**
 * The confirmed availability_set_absence: the plan is rebuilt from a fresh read and must still be the approved one;
 * the write grant, the feature switch and the actor are checked again right before the single native
 * createOrUpdateAbsence. The receipt reads the absence back through the same path as availability_read_absence. A
 * failure after the attempt is reported with what is stored, never retried.
 */
final class SetAbsenceHandler {
	/**
	 * @param SetAbsencePlan $plans the plan, read now
	 * @param GrantPolicy $policy the write grant, checked again before the write
	 * @param AbsenceGateway $native feature switch and read-back
	 * @param AbsenceWriter $writer the native create-or-update
	 * @param AbsenceView $view receipt projection
	 */
	public function __construct(private SetAbsencePlan $plans, private GrantPolicy $policy, private AbsenceGateway $native,
		private AbsenceWriter $writer, private AbsenceView $view) {}
	/**
	 * @param string $uid authenticated actor; the only account ever written
	 * @param array<string,mixed> $arguments validated arguments with confirm: true
	 * @return array<string,mixed> the receipt
	 * @throws PlanChanged when the stored state or the values differ from the approved plan: nothing was written
	 * @throws ToolFailure when the call is refused before the write
	 * @throws PolicyUnavailable when the grant cannot be read now
	 */
	public function handle(string $uid, array $arguments): array {
		$built = $this->plans->build($uid, $arguments);
		PlanState::require($arguments);
		if (!PlanState::matches((string)$built['plan'][PlanState::ARGUMENT], $arguments)) throw new PlanChanged($built['plan'] + ['warnings' => [['message' => AvailabilityMessages::planChanged()]]]);
		// Revocation between the plan and the write wins: grant, switch and actor are read again just before the sink.
		if (!$this->policy->granted($uid, 'availability', 'absence')) throw new ToolFailure(AvailabilityMessages::notAllowed());
		try {
			$enabled = $this->native->enabled();
		} catch (Throwable) {
			throw new ToolFailure(AvailabilityMessages::unavailable());
		}
		if (!$enabled) throw new ToolFailure(AvailabilityMessages::turnedOff());
		$error = null;
		try {
			$this->writer->write($uid, $built['write']);
		} catch (Throwable) {
			$error = AvailabilityMessages::writeFailed();
		}
		try {
			$stored = $this->view->project($this->native->read($uid));
		} catch (Throwable) {
			$stored = ['configured' => null, 'absence' => null];
		}
		return ['saved' => $error === null, 'mode' => $built['mode']] + $stored + ($error === null ? [] : ['error' => $error]);
	}
}
