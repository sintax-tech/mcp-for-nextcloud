<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notifications\Tools;

use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Notifications\Dismissal\DismissNotificationEffect;
use OCA\Mcp\Modules\Notifications\Dismissal\DismissNotificationPreview;
use OCA\Mcp\Modules\Notifications\Presentation\NotificationsSchema;

/** Publishes notifications_dismiss. The plan and the effect are different collaborators. */
final class DismissNotification implements WriteTool {
	/**
	 * @param DismissNotificationEffect $effect the confirmed dismiss
	 * @param DismissNotificationPreview $plans the inert plan
	 */
	public function __construct(private DismissNotificationEffect $effect, private DismissNotificationPreview $plans) {
	}

	/** @return ToolDefinition strict one-id schema and explicit destructive hint */
	public static function definition(): ToolDefinition {
		return NotificationsSchema::dismiss();
	}

	/**
	 * @param array<string,mixed> $arguments id and confirm
	 * @param ToolContext $context authenticated actor
	 * @return array<string,mixed> MCP envelope
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->effect->apply($context->userId, $arguments);
	}

	/**
	 * @param array<string,mixed> $arguments id, without an effect
	 * @param ToolContext $context authenticated actor
	 * @return array<string,mixed> plan
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return $this->plans->describe($context->userId, $arguments);
	}
}
