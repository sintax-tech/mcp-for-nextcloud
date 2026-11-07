<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Mcp\Tools\Catalog;

use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;

/** A write tool of the probe catalog, marked destructive. */
final class EditProbeTool implements WriteTool {
	/** Records that the container built this tool. */
	public function __construct() {
		BuiltTools::$names[] = 'probe_edit';
	}

	/** @return ToolDefinition the definition of the probe tool */
	public static function definition(): ToolDefinition {
		return new ToolDefinition('probe_edit', 'files', 'edit', 'Probe.', ['type' => 'object', 'properties' => new \stdClass()], destructiveHint: true);
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> a text naming the tool and the user
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return ['content' => [['type' => 'text', 'text' => 'probe_edit for ' . $context->userId]]];
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the plan
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return ['action' => 'probe_edit', 'user' => $context->userId];
	}
}
