<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Availability\Editing;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** The plan of availability_set_absence; it reads the stored absence and never writes. */
final class SetAbsencePreview {
	/** @param SetAbsencePlan $plans the plan, read now */
	public function __construct(private SetAbsencePlan $plans) {}
	/**
	 * @param string $uid authenticated actor
	 * @param array<string,mixed> $arguments validated arguments
	 * @return array<string,mixed> the signed plan
	 * @throws ToolFailure for invalid values, the feature turned off or an unknown replacement
	 */
	public function plan(string $uid, array $arguments): array { return $this->plans->build($uid, $arguments)['plan']; }
}
