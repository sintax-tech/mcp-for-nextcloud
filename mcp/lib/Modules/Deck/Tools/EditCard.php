<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Tools;

use OCA\Mcp\Modules\Deck\Editing\EditCardHandler;

/** Publishes the existing deck_edit_card tool. */
final class EditCard implements \OCA\Mcp\Mcp\Tools\WriteTool {
	/**
	 * @param \OCA\Mcp\Modules\Deck\DeckExecutionContext $execution per-user effect composition
	 * @param \OCA\Mcp\Modules\Deck\Editing\EditCardPreview $plans separate read-only plan
	 */
	public function __construct(private \OCA\Mcp\Modules\Deck\DeckExecutionContext $execution, private \OCA\Mcp\Modules\Deck\Editing\EditCardPreview $plans) {
	}

	/** @return \OCA\Mcp\Mcp\Tools\ToolDefinition unchanged static definition */
	public static function definition(): \OCA\Mcp\Mcp\Tools\ToolDefinition {
		$definition = [
				'name' => EditCardHandler::TOOL,
				'description' => \OCA\Mcp\Modules\Deck\Presentation\DeckDescriptions::TOOL_EDIT_CARD_DESCRIPTION . \OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::CONFIRM_SHARED_DESCRIPTION,
				// `duedate` has no default here on purpose: absent means "keep the current date",
				// while an explicit null clears it.
				'inputSchema' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::schema(
					[
						'cardId' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::integer(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_CARD_ID),
						'title' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::string(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_TITLE, 1, 255),
						'description' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::string(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_DESCRIPTION, 0),
						'duedate' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::nullableString(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_DUEDATE),
						'assign' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::accountList(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_ASSIGN),
						'unassign' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::accountList(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_UNASSIGN),
						'lastModified' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::integer(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_LAST_MODIFIED, 0),
						'confirm_shared' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::confirmShared(),
					],
					['cardId'],
				),
				'module' => 'deck',
				'operation' => 'edit',
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
		return $this->execution->execute(\OCA\Mcp\Modules\Deck\Editing\EditCardHandler::TOOL, $arguments, $context->userId);
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
