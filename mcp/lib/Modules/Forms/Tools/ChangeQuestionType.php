<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Tools;

use OCA\Mcp\Modules\Forms\Editing\ChangeQuestionTypeHandler;
use OCA\Mcp\Modules\Forms\Editing\ChangeQuestionTypePreview;
use OCA\Mcp\Modules\Forms\Presentation\FormsSchema;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;

/** Publishes forms_change_question_type. */
final class ChangeQuestionType implements WriteTool {
	/**
	 * @param ChangeQuestionTypeHandler $handler the tool effect
	 * @param ChangeQuestionTypePreview $preview the inert plan
	 */
	public function __construct(private ChangeQuestionTypeHandler $handler, private ChangeQuestionTypePreview $preview) {
	}

	/** {@inheritDoc} */
	public static function definition(): ToolDefinition {
		return FormsSchema::definition('forms_change_question_type');
	}

	/** {@inheritDoc} */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->handler->handle($arguments, $context->userId);
	}

	/** {@inheritDoc} */
	public function preview(array $arguments, ToolContext $context): array {
		return $this->preview->plan($arguments, $context->userId);
	}
}
