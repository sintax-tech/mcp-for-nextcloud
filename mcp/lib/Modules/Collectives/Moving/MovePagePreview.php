<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Moving;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** The plan of `collectives_move`; it reads Collectives and never changes anything. */
class MovePagePreview {
	/** @param MovePagePlan $plans the plan, read now */
	public function __construct(private MovePagePlan $plans) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @param array<string, mixed> $arguments validated collectives_move arguments
	 * @return array<string, mixed> the signed plan: origin, destination, position and the risks of the move
	 * @throws ToolFailure when a page is not admitted, the actor may not edit, or the move is refused
	 */
	public function plan(string $uid, array $arguments): array {
		return $this->plans->build($uid, $arguments)['plan'];
	}
}
