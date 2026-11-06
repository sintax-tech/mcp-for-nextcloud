<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Tools;

use OCA\Mcp\Modules\Forms\Adding\AddQuestionHandler;
use OCA\Mcp\Modules\Forms\Adding\AddQuestionPreview;
use OCA\Mcp\Modules\Forms\Presentation\FormsSchema;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;

/** Publishes forms_add_question. */
final class AddQuestion implements WriteTool {
	/**
	 * @param AddQuestionHandler $handler the tool effect
	 * @param AddQuestionPreview $preview the inert plan
	 */
	public function __construct(private AddQuestionHandler $handler, private AddQuestionPreview $preview) {
	}

	/** {@inheritDoc} */
	public static function definition(): ToolDefinition {
		return FormsSchema::definition('forms_add_question');
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
