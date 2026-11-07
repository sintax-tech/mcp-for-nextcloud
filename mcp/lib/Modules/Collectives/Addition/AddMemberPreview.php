<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Addition;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** The plan of `collectives_add_member`; it reads Collectives and Teams and never changes anything. */
class AddMemberPreview {
	/** @param AddMemberPlan $plans the plan, read now */
	public function __construct(private AddMemberPlan $plans) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @param array<string, mixed> $arguments validated collectives_add_member arguments
	 * @return array<string, mixed> the signed plan
	 * @throws ToolFailure when the call is refused
	 */
	public function plan(string $uid, array $arguments): array {
		return $this->plans->build($uid, $arguments)['plan'];
	}
}
