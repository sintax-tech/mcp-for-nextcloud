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

/** A write that wrongly implements only Tool. */
final class PlainWriteProbeTool implements Tool {
	/** Records that the container built this tool. */
	public function __construct() {
		BuiltTools::$names[] = 'probe_plain';
	}

	/** @return ToolDefinition the definition of the probe tool */
	public static function definition(): ToolDefinition {
		return new ToolDefinition('probe_plain', 'files', 'edit', 'Probe.', ['type' => 'object', 'properties' => new \stdClass()]);
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> a text naming the tool and the user
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return ['content' => [['type' => 'text', 'text' => 'probe_plain for ' . $context->userId]]];
	}
}
