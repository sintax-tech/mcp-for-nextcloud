<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Tools;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Deck\Labels\DeleteLabelHandler;
use OCA\Mcp\Modules\Deck\Presentation\DeckLabelMessages;
use OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages;
use OCA\Mcp\Modules\Deck\Presentation\DeckSchema;

/** Publishes deck_delete_label, irreversible in Deck. */
final class DeleteLabel implements \OCA\Mcp\Mcp\Tools\WriteTool {
	/**
	 * @param \OCA\Mcp\Modules\Deck\DeckExecutionContext $execution per-user effect composition
	 * @param \OCA\Mcp\Modules\Deck\Labels\LabelChangePreview $plans separate read-only signed plan
	 */
	public function __construct(private \OCA\Mcp\Modules\Deck\DeckExecutionContext $execution, private \OCA\Mcp\Modules\Deck\Labels\LabelChangePreview $plans) {
	}

	/** @return \OCA\Mcp\Mcp\Tools\ToolDefinition static definition */
	public static function definition(): \OCA\Mcp\Mcp\Tools\ToolDefinition {
		return new \OCA\Mcp\Mcp\Tools\ToolDefinition(
			DeleteLabelHandler::TOOL,
			'deck',
			'delete',
			DeckLabelMessages::TOOL_DELETE_LABEL_DESCRIPTION . DeckRefusalMessages::CONFIRM_SHARED_DESCRIPTION,
			DeckSchema::schema(
				[
					'labelId' => DeckSchema::integer(DeckLabelMessages::PARAM_LABEL_ID),
					'confirm_shared' => DeckSchema::confirmShared(),
					PlanState::ARGUMENT => PlanState::property(),
				],
				['labelId'],
			),
			'deck',
			destructiveHint: true,
		);
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments
	 * @param \OCA\Mcp\Mcp\Tools\ToolContext $context caller of this invocation
	 * @return array<string,mixed> effect result
	 */
	public function execute(array $arguments, \OCA\Mcp\Mcp\Tools\ToolContext $context): array {
		return $this->execution->execute(DeleteLabelHandler::TOOL, $arguments, $context->userId);
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments
	 * @param \OCA\Mcp\Mcp\Tools\ToolContext $context caller of this invocation
	 * @return array<string,mixed> read-only plan
	 */
	public function preview(array $arguments, \OCA\Mcp\Mcp\Tools\ToolContext $context): array {
		return $this->plans->plan(DeleteLabelHandler::TOOL, $arguments, $context->userId);
	}
}
