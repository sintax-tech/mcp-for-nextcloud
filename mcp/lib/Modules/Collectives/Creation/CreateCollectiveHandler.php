<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Creation;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Integration\CollectiveFounding;
use OCA\Mcp\Modules\Collectives\Writing\PlanConfirmation;

/**
 * The confirmed `collectives_create_collective`: the plan is rebuilt from a fresh read, which checks the name again
 * and takes the snapshot the receipt compares with, and must still be the approved one. Collectives then creates the
 * collective once, never retried and never undone; {@see CollectiveReceipt} reads back what exists.
 */
class CreateCollectiveHandler {
	/**
	 * @param CreateCollectivePlan $plans the plan, read now
	 * @param PlanConfirmation $confirmation plan states
	 * @param CollectiveFounding $founding the native creation
	 * @param CollectiveReceipt $receipts the readback
	 */
	public function __construct(
		private CreateCollectivePlan $plans,
		private PlanConfirmation $confirmation,
		private CollectiveFounding $founding,
		private CollectiveReceipt $receipts,
	) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @param array<string, mixed> $arguments validated collectives_create_collective arguments
	 * @return array<string, mixed> the receipt
	 * @throws PlanChanged when the state changed since the plan: nothing was created
	 * @throws ToolFailure when the call is refused before the attempt
	 */
	public function handle(string $uid, array $arguments): array {
		$built = $this->plans->build($uid, $arguments);
		$this->confirmation->confirm($arguments, $built['plan']);
		$answer = $this->founding->create($uid, $built['name'], $built['emoji']);
		return $this->receipts->of($uid, $built['name'], $built['snapshot'], $answer);
	}
}
