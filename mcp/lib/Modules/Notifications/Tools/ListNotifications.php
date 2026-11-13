<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notifications\Tools;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Notifications\Presentation\NotificationsSchema;
use OCA\Mcp\Modules\Notifications\Reading\ListNotificationsHandler;

/** Thin metadata read; admission remains in ToolRegistry. */
final class ListNotifications implements Tool {
	/** @param ListNotificationsHandler $reader the sole work dependency */
	public function __construct(private ListNotificationsHandler $reader) {}
	/** @return ToolDefinition strict schema and native app gate */
	public static function definition(): ToolDefinition { return NotificationsSchema::definition(); }
	/**
	 * @param array<string,mixed> $arguments requested bound
	 * @param ToolContext $context authenticated actor
	 * @return array<string,mixed> MCP envelope
	 */
	public function execute(array $arguments, ToolContext $context): array { return $this->reader->handle($context->userId, $arguments); }
}
