<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\Tools;

use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Routines\MailTasks\CreateMailCardHandler;
use OCA\Mcp\Modules\Routines\MailTasks\CreateMailCardPreview;
use OCA\Mcp\Modules\Routines\Presentation\MailCardSchema;

/** routines_create_mail_card: one email attachment saved as a new file and one card that references it, after one confirmation. */
final class CreateMailCard implements WriteTool {
	/**
	 * @param CreateMailCardHandler $handler the confirmed sequence
	 * @param CreateMailCardPreview $preview the plan of the two steps
	 */
	public function __construct(private CreateMailCardHandler $handler, private CreateMailCardPreview $preview) {
	}

	/** @return ToolDefinition the default-denied routines.mailtask write */
	public static function definition(): ToolDefinition {
		return MailCardSchema::definition();
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments with confirm: true
	 * @param ToolContext $context the actor
	 * @return array<string, mixed> the receipt, one item per step
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->handler->handle($context->userId, $arguments);
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments
	 * @param ToolContext $context the actor
	 * @return array<string, mixed> the plan, never a side effect
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return $this->preview->plan($context->userId, $arguments);
	}
}
