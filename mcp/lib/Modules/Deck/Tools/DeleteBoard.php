<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Tools;

use OCA\Mcp\Modules\Deck\Structure\DeleteBoardHandler;

/** Publishes the existing deck_delete_board tool. */
final class DeleteBoard implements \OCA\Mcp\Mcp\Tools\WriteTool {
	/**
	 * @param \OCA\Mcp\Modules\Deck\DeckExecutionContext $execution per-user effect composition
	 * @param \OCA\Mcp\Modules\Deck\Structure\DeleteBoardPreview $plans separate read-only plan
	 */
	public function __construct(private \OCA\Mcp\Modules\Deck\DeckExecutionContext $execution, private \OCA\Mcp\Modules\Deck\Structure\DeleteBoardPreview $plans) {
	}

	/** @return \OCA\Mcp\Mcp\Tools\ToolDefinition unchanged static definition */
	public static function definition(): \OCA\Mcp\Mcp\Tools\ToolDefinition {
		$definition = [
				'name' => DeleteBoardHandler::TOOL,
				// Only the owner deletes a board, so `confirm_shared` does not exist here: a board of somebody else is refused.
				'description' => \OCA\Mcp\Modules\Deck\Presentation\DeckDescriptions::TOOL_DELETE_BOARD_DESCRIPTION,
				'inputSchema' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::schema(
					['boardId' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::integer(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_BOARD_ID)],
					['boardId'],
				),
				'module' => 'deck',
				'operation' => 'delete',
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
		return $this->execution->execute(\OCA\Mcp\Modules\Deck\Structure\DeleteBoardHandler::TOOL, $arguments, $context->userId);
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
