<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Tools;

use OCA\Mcp\Modules\Deck\Editing\EditCardLabelsHandler;
use OCA\Mcp\Mcp\Confirmation\PlanState;

/** Publishes the existing deck_edit_card_labels tool. */
final class EditCardLabels implements \OCA\Mcp\Mcp\Tools\WriteTool {
	/**
	 * @param \OCA\Mcp\Modules\Deck\DeckExecutionContext $execution per-user effect composition
	 * @param \OCA\Mcp\Modules\Deck\Editing\EditCardLabelsPreview $plans separate read-only plan
	 */
	public function __construct(private \OCA\Mcp\Modules\Deck\DeckExecutionContext $execution, private \OCA\Mcp\Modules\Deck\Editing\EditCardLabelsPreview $plans) {
	}

	/** @return \OCA\Mcp\Mcp\Tools\ToolDefinition unchanged static definition */
	public static function definition(): \OCA\Mcp\Mcp\Tools\ToolDefinition {
		$definition = [
				'name' => EditCardLabelsHandler::TOOL,
				'description' => \OCA\Mcp\Modules\Deck\Presentation\DeckDescriptions::TOOL_EDIT_LABELS_DESCRIPTION . \OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::CONFIRM_SHARED_DESCRIPTION,
				'inputSchema' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::schema([
					'cardId' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::integer(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_CARD_ID),
					'add' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1],
						'maxItems' => \OCA\Mcp\Modules\Deck\Editing\CardLabelChanges::MAX_LABELS, 'description' => \OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_LABEL_ADD],
					'remove' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1],
						'maxItems' => \OCA\Mcp\Modules\Deck\Editing\CardLabelChanges::MAX_LABELS, 'description' => \OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_LABEL_REMOVE],
					'confirm_shared' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::confirmShared(),
					PlanState::ARGUMENT => PlanState::property(),
				], ['cardId']),
				'module' => 'deck', 'operation' => 'edit', 'app' => 'deck',
			];
		return new \OCA\Mcp\Mcp\Tools\ToolDefinition($definition['name'], $definition['module'], $definition['operation'],
			$definition['description'], $definition['inputSchema'], $definition['app']);
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments
	 * @param \OCA\Mcp\Mcp\Tools\ToolContext $context caller of this invocation
	 * @return array<string,mixed> unchanged effect result
	 */
	public function execute(array $arguments, \OCA\Mcp\Mcp\Tools\ToolContext $context): array {
		return $this->execution->execute(\OCA\Mcp\Modules\Deck\Editing\EditCardLabelsHandler::TOOL, $arguments, $context->userId);
	}

	/**
	 * @param array<string,mixed> $arguments validated arguments
	 * @param \OCA\Mcp\Mcp\Tools\ToolContext $context caller of this invocation
	 * @return array<string,mixed> unchanged read-only plan
	 */
	public function preview(array $arguments, \OCA\Mcp\Mcp\Tools\ToolContext $context): array {
		return $this->plans->plan($arguments, $context->userId);
	}
}
