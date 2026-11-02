<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Creation;

use OCA\Mcp\Modules\Tables\Writing\RowWriteFlow;

/** Plans one row write without applying it. */
final class CreateRowPreview {
	/**
	 * @param RowWriteFlow $flow the shared preflight and planning flow
	 */
	public function __construct(private RowWriteFlow $flow) {
	}

	/**
	 * The plan of the creation.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @param string $userId authenticated user UID
	 * @return array<string, mixed> the plan
	 * @throws \OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException for malformed arguments
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure when the creation is refused
	 * @throws \OCA\Mcp\Modules\Tables\TablesUnavailableException when Tables is not available for the user
	 */
	public function preview(array $arguments, string $userId): array {
		return $this->flow->preview(CreateRowHandler::TOOL, $arguments, $userId, false);
	}

}
