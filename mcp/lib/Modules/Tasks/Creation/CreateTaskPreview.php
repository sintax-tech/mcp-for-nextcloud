<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tasks\Creation;

use OCA\Mcp\Access\Visibility\OutputReferenceFilter;

use OCA\Mcp\Modules\Tasks\Writing\ParentTaskWrite;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use InvalidArgumentException;

/** Prepares the existing parent-aware task plan without a DAV effect. */
final class CreateTaskPreview {
	/**
	 * @param ParentTaskWrite $write existing parent-aware preparation
	 * @param OutputReferenceFilter $references availability gate before any calendar or task lookup
	 */
	public function __construct(
		private ParentTaskWrite $write,
		private OutputReferenceFilter $references,
	) {
	}

	/**
	 * The plan of the write.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @param string $userId authenticated user UID
	 * @return array<string, mixed> the plan
	 * @throws ToolFailure when access, concurrency, recovery or parent checks refuse the operation
	 * @throws InvalidArgumentException when the supplied fields are invalid
	 */
	public function plan(array $arguments, string $userId): array {
		$this->references->assertAvailable($userId);
		return $this->write->preview(CreateTaskHandler::TOOL, $arguments, $userId, false);
	}

}
