<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Tools;

use OCA\Mcp\Modules\Deck\Creation\CreateCardHandler;

/** Publishes the existing deck_create_card tool. */
final class CreateCard implements \OCA\Mcp\Mcp\Tools\WriteTool {
	/**
	 * @param \OCA\Mcp\Modules\Deck\DeckExecutionContext $execution per-user effect composition
	 * @param \OCA\Mcp\Modules\Deck\Creation\CreateCardPreview $plans separate read-only plan
	 */
	public function __construct(private \OCA\Mcp\Modules\Deck\DeckExecutionContext $execution, private \OCA\Mcp\Modules\Deck\Creation\CreateCardPreview $plans) {
	}

	/** @return \OCA\Mcp\Mcp\Tools\ToolDefinition unchanged static definition */
	public static function definition(): \OCA\Mcp\Mcp\Tools\ToolDefinition {
		$definition = [
				'name' => CreateCardHandler::TOOL,
				'description' => \OCA\Mcp\Modules\Deck\Presentation\DeckDescriptions::TOOL_CREATE_CARD_DESCRIPTION . \OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::CONFIRM_SHARED_DESCRIPTION,
				'inputSchema' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::schema(
					[
						'stackId' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::integer(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_STACK_ID),
						'title' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::string(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_TITLE, 1, 255),
						'description' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::string(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_DESCRIPTION, 0, null, ''),
						'duedate' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::nullableString(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_DUEDATE, null),
						'assignees' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::accountList(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_ASSIGNEES),
						'labels' => [
							'type' => 'array',
							'maxItems' => \OCA\Mcp\Modules\Deck\Labels\LabelInput::MAX_CARD_LABELS,
							'items' => ['type' => ['string', 'integer'], 'minLength' => 1, 'maxLength' => \OCA\Mcp\Modules\Deck\Labels\LabelInput::MAX_TITLE_LENGTH, 'minimum' => 1],
							'description' => \OCA\Mcp\Modules\Deck\Presentation\DeckLabelMessages::PARAM_CARD_LABELS,
						],
						\OCA\Mcp\Mcp\Confirmation\PlanState::ARGUMENT => \OCA\Mcp\Mcp\Confirmation\PlanState::property(),
						'confirm_shared' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::confirmShared(),
					],
					['stackId', 'title'],
				),
				'module' => 'deck',
				'operation' => 'create',
				'app' => 'deck',
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
		return $this->execution->execute(\OCA\Mcp\Modules\Deck\Creation\CreateCardHandler::TOOL, $arguments, $context->userId);
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
