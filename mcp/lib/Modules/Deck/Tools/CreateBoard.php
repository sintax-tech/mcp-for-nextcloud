<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Tools;

use OCA\Mcp\Modules\Deck\Structure\CreateBoardHandler;

/** Publishes the existing deck_create_board tool. */
final class CreateBoard implements \OCA\Mcp\Mcp\Tools\WriteTool {
	/**
	 * @param \OCA\Mcp\Modules\Deck\DeckExecutionContext $execution per-user effect composition
	 * @param \OCA\Mcp\Modules\Deck\Structure\CreateBoardPreview $plans separate read-only plan
	 */
	public function __construct(private \OCA\Mcp\Modules\Deck\DeckExecutionContext $execution, private \OCA\Mcp\Modules\Deck\Structure\CreateBoardPreview $plans) {
	}

	/** @return \OCA\Mcp\Mcp\Tools\ToolDefinition unchanged static definition */
	public static function definition(): \OCA\Mcp\Mcp\Tools\ToolDefinition {
		$definition = [
				'name' => CreateBoardHandler::TOOL,
				'description' => \OCA\Mcp\Modules\Deck\Presentation\DeckDescriptions::TOOL_CREATE_BOARD_DESCRIPTION,
				// A board that does not exist yet cannot be somebody else's, so there is no `confirm_shared`.
				'inputSchema' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::schema(
					[
						'title' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::string(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_BOARD_TITLE, 1, \OCA\Mcp\Modules\Deck\Structure\BoardBlueprint::MAX_TITLE_LENGTH),
						'color' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::string(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_BOARD_COLOR, 6, 7),
						'stacks' => [
							'type' => 'array',
							'maxItems' => \OCA\Mcp\Modules\Deck\Structure\BoardBlueprint::MAX_STACKS,
							'description' => \OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_BOARD_STACKS,
							'items' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::schema(
								[
									'title' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::string(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_STACK_TITLE, 1, \OCA\Mcp\Modules\Deck\Structure\BoardBlueprint::MAX_TITLE_LENGTH),
									'cards' => [
										'type' => 'array',
										'maxItems' => \OCA\Mcp\Modules\Deck\Structure\BoardBlueprint::MAX_CARDS,
										'description' => \OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_STACK_CARDS,
										'items' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::schema(
											[
												'title' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::string(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_TITLE, 1, 255),
												'description' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::string(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_DESCRIPTION, 0),
												'duedate' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::nullableString(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_DUEDATE),
												'assignees' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::accountList(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_ASSIGNEES),
											],
											['title'],
										),
									],
								],
								['title'],
							),
						],
					],
					['title'],
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
		return $this->execution->execute(\OCA\Mcp\Modules\Deck\Structure\CreateBoardHandler::TOOL, $arguments, $context->userId);
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
