<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Activity\Tools;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Activity\Presentation\ActivitySchema;
use OCA\Mcp\Modules\Activity\Reading\ListActivityHandler;

/** Bounded own-feed metadata read, without marking anything read. */
final class ListActivity implements Tool {
	/** @param ListActivityHandler $feed the single read dependency */
	public function __construct(private ListActivityHandler $feed) {}
	/** @return ToolDefinition opt-in Activity read */
	public static function definition(): ToolDefinition { return ActivitySchema::definition(); }
	/**
	 * @param array<string,mixed> $arguments validated input
	 * @param ToolContext $context actor
	 * @return array<string,mixed> MCP result
	 */
	public function execute(array $arguments, ToolContext $context): array { return $this->feed->handle($context->userId, $arguments); }
}
