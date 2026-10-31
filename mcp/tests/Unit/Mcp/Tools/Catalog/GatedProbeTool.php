<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Mcp\Tools\Catalog;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;

/** A read tool that exists only with the probe.feature capability. */
final class GatedProbeTool implements Tool {
	/** Records that the container built this tool. */
	public function __construct() {
		BuiltTools::$names[] = 'probe_gated';
	}

	/** @return ToolDefinition the definition of the probe tool */
	public static function definition(): ToolDefinition {
		return new ToolDefinition('probe_gated', 'files', 'read', 'Probe.', ['type' => 'object', 'properties' => new \stdClass()], requires: ['probe.feature']);
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> a text naming the tool and the user
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return ['content' => [['type' => 'text', 'text' => 'probe_gated for ' . $context->userId]]];
	}
}
