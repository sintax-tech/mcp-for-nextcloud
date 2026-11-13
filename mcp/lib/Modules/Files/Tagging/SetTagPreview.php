<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tagging;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;

/** Plans files_set_tag without writing anything. */
final class SetTagPreview {
	/** @param SetTagPlan $plans the plan, read now */
	public function __construct(private SetTagPlan $plans) {
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments
	 * @param string $uid authenticated actor
	 * @return array<string,mixed> the signed plan
	 * @throws ArgumentValidationException when both or neither of tag_id and tag are given
	 * @throws ToolFailure the not-found refusal for an unreachable or unchangeable file and an unusable tag
	 */
	public function plan(array $arguments, string $uid): array {
		return $this->plans->build($uid, $arguments)['plan'];
	}
}
