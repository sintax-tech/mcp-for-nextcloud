<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Editing;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** The plan of `collectives_edit`; it reads the page and never changes anything, nor takes a lock. */
class EditPagePreview {
	/** @param EditPagePlan $plans the plan, read now */
	public function __construct(private EditPagePlan $plans) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @param array<string, mixed> $arguments validated collectives_edit arguments
	 * @return array<string, mixed> the signed plan, with the diff and the etag to confirm with
	 * @throws ToolFailure when the page is not admitted, the actor may not edit, or the change is refused
	 */
	public function plan(string $uid, array $arguments): array {
		return $this->plans->build($uid, $arguments)['plan'];
	}
}
