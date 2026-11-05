<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tasks\Deletion;

use OCA\Mcp\Modules\Tasks\Writing\TaskWriteFlow;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use InvalidArgumentException;
use RuntimeException;

/** Prepares the existing task write plan without a DAV effect. */
final class DeleteTaskPreview {
	/** MCP tool served by this work class. */
	public const TOOL = 'tasks_delete_task';

	/**
	 * @param TaskWriteFlow $flow existing task preparation and native DAV write
	 */
	public function __construct(private TaskWriteFlow $flow) {
	}

	/**
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @param string $userId authenticated UID
	 * @return array<string, mixed> before/after plan
	 * @throws ToolFailure when access, ETag, confirmation or retention refuses the write
	 * @throws InvalidArgumentException when the supplied arguments are invalid
	 * @throws RuntimeException when the native effect or read-back fails
	 */
	public function plan(array $arguments, string $userId): array {
		return $this->flow->prepare(self::TOOL, $arguments, $userId)['plan'];
	}
}
