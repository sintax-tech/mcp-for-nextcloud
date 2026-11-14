<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 Roman Matkivskyy <roman91@live.it>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Tools;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Deck\Labels\LabelInput;
use OCA\Mcp\Modules\Deck\Labels\UpdateLabelHandler;
use OCA\Mcp\Modules\Deck\Presentation\DeckLabelMessages;
use OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages;
use OCA\Mcp\Modules\Deck\Presentation\DeckSchema;

/** Publishes deck_update_label. */
final class UpdateLabel implements \OCA\Mcp\Mcp\Tools\WriteTool {
	/**
	 * @param \OCA\Mcp\Modules\Deck\DeckExecutionContext $execution per-user effect composition
	 * @param \OCA\Mcp\Modules\Deck\Labels\LabelChangePreview $plans separate read-only signed plan
	 */
	public function __construct(private \OCA\Mcp\Modules\Deck\DeckExecutionContext $execution, private \OCA\Mcp\Modules\Deck\Labels\LabelChangePreview $plans) {
	}

	/** @return \OCA\Mcp\Mcp\Tools\ToolDefinition static definition */
	public static function definition(): \OCA\Mcp\Mcp\Tools\ToolDefinition {
		return new \OCA\Mcp\Mcp\Tools\ToolDefinition(
			UpdateLabelHandler::TOOL,
			'deck',
			'labels',
			DeckLabelMessages::TOOL_UPDATE_LABEL_DESCRIPTION . DeckRefusalMessages::CONFIRM_SHARED_DESCRIPTION,
			DeckSchema::schema(
				[
					'labelId' => DeckSchema::integer(DeckLabelMessages::PARAM_LABEL_ID),
					'title' => DeckSchema::string(DeckLabelMessages::PARAM_LABEL_TITLE, 1, LabelInput::MAX_TITLE_LENGTH),
					'color' => DeckSchema::string(DeckLabelMessages::PARAM_LABEL_COLOR, 6, 7),
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
		return $this->execution->execute(UpdateLabelHandler::TOOL, $arguments, $context->userId);
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments
	 * @param \OCA\Mcp\Mcp\Tools\ToolContext $context caller of this invocation
	 * @return array<string,mixed> read-only plan
	 */
	public function preview(array $arguments, \OCA\Mcp\Mcp\Tools\ToolContext $context): array {
		return $this->plans->plan(UpdateLabelHandler::TOOL, $arguments, $context->userId);
	}
}
