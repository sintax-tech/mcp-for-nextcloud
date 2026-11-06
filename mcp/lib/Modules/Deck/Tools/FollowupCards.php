<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Tools;

use OCA\Mcp\Modules\Deck\Reading\FollowupCardsHandler;

/** Publishes the existing deck_followup_cards tool. */
final class FollowupCards implements \OCA\Mcp\Mcp\Tools\Tool {
	/**
	 * @param \OCA\Mcp\Modules\Deck\DeckExecutionContext $execution per-user effect composition
	 */
	public function __construct(private \OCA\Mcp\Modules\Deck\DeckExecutionContext $execution) {
	}

	/** @return \OCA\Mcp\Mcp\Tools\ToolDefinition unchanged static definition */
	public static function definition(): \OCA\Mcp\Mcp\Tools\ToolDefinition {
		$definition = [
				'name' => FollowupCardsHandler::TOOL,
				'description' => \OCA\Mcp\Modules\Deck\Presentation\DeckDescriptions::TOOL_FOLLOWUP_CARDS_DESCRIPTION,
				// Every argument is optional: the defaults answer the question the tool exists for.
				'inputSchema' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::schema(
					[
						'status' => [
							'type' => 'string',
							'enum' => \OCA\Mcp\Modules\Deck\Integration\CardCriteria::STATUSES,
							'default' => \OCA\Mcp\Modules\Deck\Integration\CardCriteria::STATUS_OVERDUE,
							'description' => \OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_STATUS,
						],
						'assignee' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::string(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_ASSIGNEE, 1, 64),
						'boardId' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::integer(\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_BOARD_ID),
						'dueBefore' => ['type' => 'string', 'description' => \OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_DUE_BEFORE],
						'limit' => \OCA\Mcp\Modules\Deck\Presentation\DeckSchema::integer(
							\OCA\Mcp\Modules\Deck\Presentation\DeckRefusalMessages::PARAM_LIMIT,
							1,
							FollowupCardsHandler::MAX_LIMIT,
							FollowupCardsHandler::DEFAULT_LIMIT,
						),
					],
					[],
				),
				'module' => 'deck',
				'operation' => 'read',
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
		return $this->execution->execute(\OCA\Mcp\Modules\Deck\Reading\FollowupCardsHandler::TOOL, $arguments, $context->userId);
	}
}
