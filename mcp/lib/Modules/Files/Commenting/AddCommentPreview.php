<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Commenting;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** Plans files_add_comment without writing anything. */
final class AddCommentPreview {
	/** @param AddCommentPlan $plans the plan, read now */
	public function __construct(private AddCommentPlan $plans) {
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments
	 * @param string $uid authenticated actor
	 * @return array<string,mixed> the signed plan
	 * @throws ToolFailure for an empty or too long text, a refused mention, or an unreachable item
	 */
	public function plan(array $arguments, string $uid): array {
		return $this->plans->build($uid, $arguments)['plan'];
	}
}
