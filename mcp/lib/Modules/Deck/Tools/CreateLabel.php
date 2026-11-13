<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 Roman Matkivskyy <roman91@live.it>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Tools;

use OCA\Mcp\Modules\Deck\Labels\CreateLabelHandler;
use OCA\Mcp\Modules\Deck\Labels\LabelInput;
use OCA\Mcp\Modules\Deck\Presentation\DeckLabelMessages;
use OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages;
use OCA\Mcp\Modules\Deck\Presentation\DeckSchema;

/** Publishes deck_create_label. */
final class CreateLabel implements \OCA\Mcp\Mcp\Tools\WriteTool {
	/**
	 * @param \OCA\Mcp\Modules\Deck\DeckExecutionContext $execution per-user effect composition
	 * @param \OCA\Mcp\Modules\Deck\Labels\CreateLabelPreview $plans separate read-only plan
	 */
	public function __construct(private \OCA\Mcp\Modules\Deck\DeckExecutionContext $execution, private \OCA\Mcp\Modules\Deck\Labels\CreateLabelPreview $plans) {
	}

	/** @return \OCA\Mcp\Mcp\Tools\ToolDefinition static definition */
	public static function definition(): \OCA\Mcp\Mcp\Tools\ToolDefinition {
		return new \OCA\Mcp\Mcp\Tools\ToolDefinition(
			CreateLabelHandler::TOOL,
			'deck',
			'labels',
			DeckLabelMessages::TOOL_CREATE_LABEL_DESCRIPTION . DeckRefusalMessages::CONFIRM_SHARED_DESCRIPTION,
			DeckSchema::schema(
				[
					'boardId' => DeckSchema::integer(DeckRefusalMessages::PARAM_BOARD_ID),
					'title' => DeckSchema::string(DeckLabelMessages::PARAM_LABEL_TITLE, 1, LabelInput::MAX_TITLE_LENGTH),
					'color' => DeckSchema::string(DeckLabelMessages::PARAM_LABEL_COLOR, 6, 7),
					\OCA\Mcp\Mcp\Confirmation\PlanState::ARGUMENT => \OCA\Mcp\Mcp\Confirmation\PlanState::property(),
						'confirm_shared' => DeckSchema::confirmShared(),
				],
				['boardId', 'title'],
			),
			'deck',
			destructiveHint: false,
		);
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments
	 * @param \OCA\Mcp\Mcp\Tools\ToolContext $context caller of this invocation
	 * @return array<string,mixed> effect result
	 */
	public function execute(array $arguments, \OCA\Mcp\Mcp\Tools\ToolContext $context): array {
		return $this->execution->execute(CreateLabelHandler::TOOL, $arguments, $context->userId);
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments
	 * @param \OCA\Mcp\Mcp\Tools\ToolContext $context caller of this invocation
	 * @return array<string,mixed> read-only plan
	 */
	public function preview(array $arguments, \OCA\Mcp\Mcp\Tools\ToolContext $context): array {
		return $this->plans->plan($arguments, $context->userId);
	}
}
