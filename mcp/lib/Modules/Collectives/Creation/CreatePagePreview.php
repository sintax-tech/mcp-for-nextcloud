<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Creation;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** The plan of `collectives_create`; it reads Collectives and never changes anything. */
class CreatePagePreview {
	/** @param CreatePagePlan $plans the plan, read now */
	public function __construct(private CreatePagePlan $plans) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @param array<string, mixed> $arguments validated collectives_create arguments
	 * @return array<string, mixed> the signed plan
	 * @throws ToolFailure when the collective or the parent is not admitted, or the actor may not edit
	 */
	public function plan(string $uid, array $arguments): array {
		return $this->plans->build($uid, $arguments)['plan'];
	}
}
